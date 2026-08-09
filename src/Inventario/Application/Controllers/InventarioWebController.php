<?php
namespace Src\Inventario\Application\Controllers;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Src\Auditoria\Application\Services\RegistrarAuditoria;
use Src\Inventario\Application\Services\RegistrarMovimientoInventario;
use Src\Inventario\Infrastructure\Models\CategoriaRepuestoEloquentModel;
use Src\Inventario\Infrastructure\Models\MovimientoInventarioEloquentModel;
use Src\Inventario\Infrastructure\Models\OrdenRepuestoEloquentModel;
use Src\Inventario\Infrastructure\Models\ProveedorEloquentModel;
use Src\Inventario\Infrastructure\Models\RepuestoEloquentModel;
use Src\Inventario\Infrastructure\Requests\ActualizarRepuestoRequest;
use Src\Inventario\Infrastructure\Requests\GuardarRepuestoRequest;
use Src\Inventario\Infrastructure\Requests\RegistrarMovimientoRequest;
use Src\Inventario\Infrastructure\Requests\UsarRepuestoOrdenRequest;
use Src\OrdenTrabajo\Infrastructure\Models\OrdenTrabajoEloquentModel;
use App\Rules\TelefonoEcuatoriano;
use Src\Pago\Application\Services\CalculadorTotalOrden;
use Src\OrdenTrabajo\Application\Services\ValidarPreparacionTrabajo;
use Src\OrdenTrabajo\Application\Services\AutorizarMecanicoOrden;
use Src\OrdenTrabajo\Infrastructure\Models\OrdenRepuestoRequeridoEloquentModel;
use Src\HistorialVehicular\Application\Services\RegistrarEventoVehiculo;
class InventarioWebController extends Controller
{
    public function index(Request $request): Response
    {
        $buscar = trim((string) $request->input("buscar"));
        $estado = $request->input("estado");
        $categoria = $request->input("categoria");
        $proveedor = $request->input("proveedor");
        $bajo = $request->boolean("bajo");
        $repuestos = RepuestoEloquentModel::with([
            "categoria:id,nombre",
            "proveedor:id,nombre",
        ])
            ->when(
                $buscar,
                fn($q) => $q->where(
                    fn($s) => $s
                        ->where("codigo", "ilike", "%{$buscar}%")
                        ->orWhere("nombre", "ilike", "%{$buscar}%")
                        ->orWhereHas(
                            "proveedor",
                            fn($p) => $p->where(
                                "nombre",
                                "ilike",
                                "%{$buscar}%",
                            ),
                        ),
                ),
            )
            ->when($estado, fn($q) => $q->where("estado", $estado))
            ->when($categoria, fn($q) => $q->where("categoria_id", $categoria))
            ->when($proveedor, fn($q) => $q->where("proveedor_id", $proveedor))
            ->when(
                $bajo,
                fn($q) => $q
                    ->where("stock_actual", ">", 0)
                    ->whereColumn("stock_actual", "<=", "stock_minimo"),
            )
            ->orderBy("nombre")
            ->paginate(15)
            ->withQueryString();
        $gestiona = $request->user()->can("inventario.gestionar");
        $relaciones = [
            "repuesto:id,codigo,nombre,unidad",
            "orden" => fn($q) => $gestiona
                ? $q
                : $q->visiblePara($request->user()),
        ];
        if ($gestiona) {
            $relaciones[] = "usuario:id,name";
        }
        $movimientos = MovimientoInventarioEloquentModel::with($relaciones)
            ->latest("created_at")
            ->limit(30)
            ->get()
            ->map(
                fn($m) => [
                    "id" => $m->id,
                    "tipo" => $m->tipo,
                    "cantidad" => $m->cantidad,
                    "stock_anterior" => $m->stock_anterior,
                    "stock_resultante" => $m->stock_resultante,
                    "motivo" => $m->motivo,
                    "created_at" => $m->created_at?->toIso8601String(),
                    "repuesto" => $m->repuesto?->only([
                        "codigo",
                        "nombre",
                        "unidad",
                    ]),
                    "orden" => $m->orden?->only(["id", "numero"]),
                    "usuario" => $gestiona
                        ? $m->usuario?->only(["name"])
                        : null,
                ],
            );
        $catalogos = $this->catalogosDatos();
        $activos = RepuestoEloquentModel::where("estado", "activo");
        return Inertia::render("Inventario/index", [
            ...$catalogos,
            "vista" => $request->route("vista") ?? "resumen",
            "repuestos" => $repuestos,
            "movimientos" => $movimientos,
            "filtros" => [
                "buscar" => $buscar,
                "estado" => $estado ?? "",
                "categoria" => $categoria ?? "",
                "proveedor" => $proveedor ?? "",
                "bajo" => $bajo,
            ],
            "stats" => [
                "total" => (clone $activos)->count(),
                "ok" => (clone $activos)
                    ->whereColumn("stock_actual", ">", "stock_minimo")
                    ->count(),
                "bajos" => (clone $activos)
                    ->where("stock_actual", ">", 0)
                    ->whereColumn("stock_actual", "<=", "stock_minimo")
                    ->count(),
                "agotados" => (clone $activos)
                    ->where("stock_actual", "<=", 0)
                    ->count(),
            ],
        ]);
    }
    public function catalogos(): Response
    {
        return Inertia::render("Inventario/catalogos", $this->catalogosDatos());
    }
    public function show(Request $r, RepuestoEloquentModel $p): Response
    {
        $f = $r->validate([
            "tipo" => "nullable|in:entrada,salida,ajuste,reversion",
            "desde" => "nullable|date",
            "hasta" => "nullable|date|after_or_equal:desde",
        ]);
        $tipo = $f["tipo"] ?? null;
        $desde = $f["desde"] ?? null;
        $hasta = $f["hasta"] ?? null;
        $gestiona = $r->user()->can("inventario.gestionar");
        $p->load(["categoria:id,nombre", "proveedor:id,nombre"]);
        $relaciones = [
            "origen:id,tipo,cantidad",
            "orden" => fn($q) => $gestiona ? $q : $q->visiblePara($r->user()),
        ];
        if ($gestiona) {
            $relaciones[] = "usuario:id,name";
        }
        $movimientos = MovimientoInventarioEloquentModel::with($relaciones)
            ->where("repuesto_id", $p->id)
            ->when(
                !$gestiona,
                fn($q) => $q->where(
                    fn($v) => $v
                        ->whereNull("orden_id")
                        ->orWhereHas(
                            "orden",
                            fn($o) => $o->visiblePara($r->user()),
                        ),
                ),
            )
            ->when($tipo, fn($q) => $q->where("tipo", $tipo))
            ->when($desde, fn($q) => $q->whereDate("created_at", ">=", $desde))
            ->when($hasta, fn($q) => $q->whereDate("created_at", "<=", $hasta))
            ->latest("created_at")
            ->paginate(25)
            ->withQueryString()
            ->through(
                fn($m) => [
                    "id" => $m->id,
                    "tipo" => $m->tipo,
                    "cantidad" => $m->cantidad,
                    "stock_anterior" => $m->stock_anterior,
                    "stock_resultante" => $m->stock_resultante,
                    "costo_unitario" => $gestiona ? $m->costo_unitario : null,
                    "motivo" => $m->motivo,
                    "created_at" => $m->created_at?->toIso8601String(),
                    "movimiento_origen_id" => $m->movimiento_origen_id,
                    "orden" => $m->orden?->only(["id", "numero"]),
                    "usuario" => $gestiona
                        ? $m->usuario?->only(["name"])
                        : null,
                    "origen" => $m->origen?->only(["tipo", "cantidad"]),
                ],
            );
        $repuesto = [
            "id" => $p->id,
            "codigo" => $p->codigo,
            "nombre" => $p->nombre,
            "descripcion" => $p->descripcion,
            "unidad" => $p->unidad,
            "stock_actual" => $p->stock_actual,
            "stock_minimo" => $p->stock_minimo,
            "costo_referencia" => $gestiona ? $p->costo_referencia : null,
            "precio_venta" => $p->precio_venta,
            "estado" => $p->estado,
            "categoria" => $p->categoria?->only(["nombre"]),
            "proveedor" => $p->proveedor?->only(["nombre"]),
        ];
        return Inertia::render("Inventario/show", [
            "repuesto" => $repuesto,
            "movimientos" => $movimientos,
            "filtros" => [
                "tipo" => $tipo ?? "",
                "desde" => $desde ?? "",
                "hasta" => $hasta ?? "",
            ],
        ]);
    }
    public function edit(RepuestoEloquentModel $p): Response
    {
        $p->load(["categoria", "proveedor"]);
        return Inertia::render("Inventario/form", [
            "repuesto" => $p,
            ...$this->catalogosDatos(),
        ]);
    }
    public function storeCategoria(
        Request $r,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $r->validate([
            "nombre" =>
                "required|string|max:120|unique:categorias_repuesto,nombre",
            "descripcion" => "nullable|string",
        ]);
        $c = CategoriaRepuestoEloquentModel::create([
            ...$d,
            "estado" => "activo",
            "creado_por" => $r->user()->id,
            "actualizado_por" => $r->user()->id,
        ]);
        $a->registrar(
            "categoria_repuesto.creada",
            "categoria_repuesto",
            $c->id,
            [],
            $r,
        );
        return back()->with("success", "Categoría creada.");
    }
    public function updateCategoria(
        Request $r,
        CategoriaRepuestoEloquentModel $c,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $r->validate([
            "nombre" => [
                "required",
                "string",
                "max:120",
                Rule::unique("categorias_repuesto")->ignore($c->id),
            ],
            "descripcion" => "nullable|string",
        ]);
        $antes = $c->only(["nombre", "descripcion"]);
        $c->update([...$d, "actualizado_por" => $r->user()->id]);
        $a->registrar(
            "categoria_repuesto.actualizada",
            "categoria_repuesto",
            $c->id,
            [
                "antes" => $antes,
                "despues" => $c->only(["nombre", "descripcion"]),
            ],
            $r,
        );
        return back()->with("success", "Categoría actualizada.");
    }
    public function estadoCategoria(
        Request $r,
        CategoriaRepuestoEloquentModel $c,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        $this->cambiarEstadoCatalogo($r, $c, $a, "categoria_repuesto");
        return back()->with("success", "Estado de la categoría actualizado.");
    }
    public function storeProveedor(
        Request $r,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $this->validarProveedor($r);
        $p = ProveedorEloquentModel::create([
            ...$d,
            "estado" => "activo",
            "creado_por" => $r->user()->id,
            "actualizado_por" => $r->user()->id,
        ]);
        $a->registrar("proveedor.creado", "proveedor", $p->id, [], $r);
        return back()->with("success", "Proveedor creado.");
    }
    public function updateProveedor(
        Request $r,
        ProveedorEloquentModel $proveedor,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $this->validarProveedor($r, $proveedor);
        $antes = $proveedor->only([
            "documento",
            "nombre",
            "contacto",
            "telefono",
            "email",
        ]);
        $proveedor->update([...$d, "actualizado_por" => $r->user()->id]);
        $a->registrar(
            "proveedor.actualizado",
            "proveedor",
            $proveedor->id,
            [
                "antes" => $antes,
                "despues" => $proveedor->only(array_keys($antes)),
            ],
            $r,
        );
        return back()->with("success", "Proveedor actualizado.");
    }
    public function estadoProveedor(
        Request $r,
        ProveedorEloquentModel $proveedor,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        $this->cambiarEstadoCatalogo($r, $proveedor, $a, "proveedor");
        return back()->with("success", "Estado del proveedor actualizado.");
    }
    public function storeRepuesto(
        GuardarRepuestoRequest $r,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        $p = RepuestoEloquentModel::create([
            ...$r->validated(),
            "estado" => "activo",
            "creado_por" => $r->user()->id,
            "actualizado_por" => $r->user()->id,
        ]);
        $a->registrar("repuesto.creado", "repuesto", $p->id, [], $r);
        return back()->with(
            "success",
            "Repuesto creado. Registra una entrada para aumentar su stock.",
        );
    }
    public function updateRepuesto(
        ActualizarRepuestoRequest $r,
        RepuestoEloquentModel $p,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        $d = $r->validated();
        if (
            $p->unidad !== $d["unidad"] &&
            MovimientoInventarioEloquentModel::where(
                "repuesto_id",
                $p->id,
            )->exists()
        ) {
            throw ValidationException::withMessages([
                "unidad" =>
                    "No se puede cambiar la unidad porque el repuesto ya tiene movimientos.",
            ]);
        }
        $antes = $p->only(array_keys($d));
        $p->update([...$d, "actualizado_por" => $r->user()->id]);
        $a->registrar(
            "repuesto.actualizado",
            "repuesto",
            $p->id,
            ["antes" => $antes, "despues" => $p->only(array_keys($d))],
            $r,
        );
        return redirect()
            ->route("inventario.repuestos.show", $p)
            ->with("success", "Repuesto actualizado.");
    }
    public function movimiento(
        RegistrarMovimientoRequest $r,
        RegistrarMovimientoInventario $s,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        $m = $s->registrar(
            $r->validated("repuesto_id"),
            (string) $r->validated("cantidad"),
            $r->validated("tipo"),
            $r->validated("motivo"),
            $r->user()->id,
            null,
            $r->validated("costo_unitario"),
        );
        $a->registrar(
            "inventario.movimiento",
            "movimiento_inventario",
            $m->id,
            ["tipo" => $m->tipo, "cantidad" => $m->cantidad],
            $r,
        );
        return back()->with("success", "Movimiento registrado.");
    }
    public function estado(
        Request $r,
        RepuestoEloquentModel $p,
        RegistrarAuditoria $a,
    ): RedirectResponse {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $r->validate([
            "estado" => "required|in:activo,inactivo,archivado",
        ]);
        $anterior = $p->estado;
        $p->update([
            "estado" => $d["estado"],
            "actualizado_por" => $r->user()->id,
        ]);
        $a->registrar(
            "repuesto.estado_cambiado",
            "repuesto",
            $p->id,
            ["anterior" => $anterior, "nuevo" => $p->estado],
            $r,
        );
        return back()->with("success", "Estado del repuesto actualizado.");
    }
    public function usar(
        UsarRepuestoOrdenRequest $r,
        OrdenTrabajoEloquentModel $orden,
        RegistrarMovimientoInventario $s,
        ValidarPreparacionTrabajo $preparacion,
        RegistrarAuditoria $a,
        RegistrarEventoVehiculo $historial,
    ): RedirectResponse {
        return $this->procesarUsoOrden($r, $orden, $s, $preparacion, $a, $historial);
        $this->autorizarOrden($r, $orden);
        $preparacion->validar($orden->id);
        $uso = DB::transaction(function () use ($r, $orden, $s) {
            $bloqueada = OrdenTrabajoEloquentModel::whereKey($orden->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (
                !in_array(
                    $bloqueada->estado,
                    ["en_diagnostico", "en_reparacion"],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    "orden" =>
                        "La orden no permite consumir repuestos en su estado actual.",
                ]);
            }
            $p = RepuestoEloquentModel::whereKey($r->validated("repuesto_id"))
                ->where("estado", "activo")
                ->lockForUpdate()
                ->firstOrFail();
            $requerimiento = OrdenRepuestoRequeridoEloquentModel::whereKey(
                    $r->validated("requerimiento_id"),
                )
                    ->where("orden_id", $bloqueada->id)
                    ->where("estado", "<>", "retirado")
                    ->lockForUpdate()
                    ->first();
            if (!$requerimiento) {
                throw ValidationException::withMessages([
                    "requerimientoId" =>
                        "El requerimiento no pertenece a esta orden.",
                ]);
            }
            if (
                $requerimiento &&
                $requerimiento->repuesto_id &&
                $requerimiento->repuesto_id !== $p->id
            ) {
                throw ValidationException::withMessages([
                    "repuestoId" =>
                        "El repuesto no coincide con el requerimiento seleccionado.",
                ]);
            }
            $usada = (string) OrdenRepuestoEloquentModel::where('requerimiento_id', $requerimiento->id)->whereNull('revertido_en')->sum('cantidad');
            $restante = BigDecimal::of((string) $requerimiento->cantidad)->minus(BigDecimal::of($usada));
            if (BigDecimal::of((string) $r->validated("cantidad"))->isGreaterThan($restante)) {
                throw ValidationException::withMessages([
                    "cantidad" =>
                        "La cantidad utilizada supera el saldo requerido ({$restante}).",
                ]);
            }
            if ($requerimiento && !$requerimiento->repuesto_id) {
                $requerimiento->update([
                    "repuesto_id" => $p->id,
                    "estado" => "requerido",
                ]);
            }
            $cantidad = (string) BigDecimal::of(
                (string) $r->validated("cantidad"),
            )->negated();
            $m = $s->registrar(
                $p->id,
                $cantidad,
                "salida",
                "Uso en orden " .
                    $bloqueada->numero .
                    ($r->validated("observaciones")
                        ? ": " . $r->validated("observaciones")
                        : ""),
                $r->user()->id,
                $bloqueada->id,
                $p->costo_referencia,
            );
            return OrdenRepuestoEloquentModel::create([
                "orden_id" => $bloqueada->id,
                "repuesto_id" => $p->id,
                "requerimiento_id" => $requerimiento?->id,
                "cantidad" => $r->validated("cantidad"),
                "precio_unitario" => $p->precio_venta,
                "codigo_snapshot" => $p->codigo,
                "nombre_snapshot" => $p->nombre,
                "unidad_snapshot" => $p->unidad,
                "movimiento_salida_id" => $m->id,
                "registrado_por" => $r->user()->id,
            ]);
        });
        $a->registrar(
            "orden.repuesto_usado",
            "orden_repuesto",
            $uso->id,
            [],
            $r,
        );
        return back()->with(
            "success",
            "Repuesto utilizado y descontado del inventario.",
        );
    }
    public function revertir(
        Request $r,
        OrdenRepuestoEloquentModel $uso,
        RegistrarMovimientoInventario $s,
        CalculadorTotalOrden $calculador,
        RegistrarAuditoria $a,
        RegistrarEventoVehiculo $historial,
    ): RedirectResponse {
        return $this->procesarReversionOrden($r, $uso, $s, $calculador, $a, $historial);
        abort_unless(
            $r->user()->can("inventario.gestionar") ||
                $r->user()->can("inventario.consumir"),
            403,
        );
        $orden = OrdenTrabajoEloquentModel::findOrFail($uso->orden_id);
        $this->autorizarOrden($r, $orden);
        if (in_array($orden->estado, ["finalizada", "entregada"], true)) {
            throw ValidationException::withMessages([
                "movimiento" =>
                    "No se pueden revertir repuestos después de finalizar la orden.",
            ]);
        }
        $r->validate(["motivo" => "required|string"]);
        DB::transaction(function () use ($r, $uso, $s, $calculador) {
            $orden = OrdenTrabajoEloquentModel::whereKey($uso->orden_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($orden->estado, ["finalizada", "entregada"], true)) {
                throw ValidationException::withMessages([
                    "movimiento" =>
                        "No se pueden revertir repuestos después de finalizar la orden.",
                ]);
            }
            if (
                DB::table("facturas_orden")
                    ->where("orden_id", $uso->orden_id)
                    ->where("estado", "emitida")
                    ->exists()
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "movimiento" =>
                        "No se puede modificar una orden con factura vigente.",
                ]);
            }
            $bloqueado = OrdenRepuestoEloquentModel::whereKey($uso->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($bloqueado->revertido_en) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "movimiento" => "Este uso ya fue revertido.",
                ]);
            }
            $resumen = $calculador->calcular($bloqueado->orden_id);
            $reduccion = BigDecimal::of($bloqueado->cantidad)->multipliedBy(
                BigDecimal::of($bloqueado->precio_unitario),
            );
            if (
                BigDecimal::of($resumen["total"])
                    ->minus($reduccion)
                    ->isLessThan(BigDecimal::of($resumen["pagado"]))
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "movimiento" =>
                        "Primero anula los pagos que excederían el nuevo total de la orden.",
                ]);
            }
            $origen = MovimientoInventarioEloquentModel::findOrFail(
                $bloqueado->movimiento_salida_id,
            );
            $s->revertir($origen, $r->input("motivo"), $r->user()->id);
            $bloqueado->update([
                "revertido_en" => now(),
                "revertido_por" => $r->user()->id,
            ]);
        });
        $a->registrar(
            "orden.repuesto_revertido",
            "orden_repuesto",
            $uso->id,
            [],
            $r,
        );
        return back()->with("success", "Se creó el movimiento compensatorio.");
    }
    private function procesarUsoOrden(UsarRepuestoOrdenRequest $r, OrdenTrabajoEloquentModel $orden, RegistrarMovimientoInventario $movimientos, ValidarPreparacionTrabajo $preparacion, RegistrarAuditoria $auditoria, RegistrarEventoVehiculo $historial): RedirectResponse
    {
        $uso = DB::transaction(function () use ($r, $orden, $movimientos, $preparacion) {
            $bloqueada = OrdenTrabajoEloquentModel::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            $this->autorizarOrden($r, $bloqueada);
            if (! in_array($bloqueada->estado, ['en_diagnostico', 'esperando_repuestos', 'en_reparacion'], true)) throw ValidationException::withMessages(['orden' => 'La orden no permite registrar repuestos utilizados.']);
            $preparacion->validar($bloqueada->id);
            $requerimiento = OrdenRepuestoRequeridoEloquentModel::whereKey($r->validated('requerimiento_id'))->where('orden_id', $bloqueada->id)->where('estado', 'aprobado')->lockForUpdate()->first();
            if (! $requerimiento) throw ValidationException::withMessages(['requerimientoId' => 'Selecciona un requerimiento aprobado de esta orden.']);
            $usada = BigDecimal::of((string) OrdenRepuestoEloquentModel::where('requerimiento_id', $requerimiento->id)->whereNull('revertido_en')->sum('cantidad'));
            $restante = BigDecimal::of((string) $requerimiento->cantidad)->minus($usada);
            $cantidad = BigDecimal::of((string) $r->validated('cantidad'));
            if ($cantidad->isGreaterThan($restante)) throw ValidationException::withMessages(['cantidad' => "La cantidad supera el saldo aprobado ({$restante})."]);

            $repuesto = null;
            $movimiento = null;
            $precio = (string) $requerimiento->precio_unitario_aprobado;
            $codigo = null;
            $nombre = $requerimiento->descripcion;
            $unidad = $requerimiento->unidad_snapshot;
            $facturable = $requerimiento->fuente_suministro !== 'cliente';
            if ($requerimiento->fuente_suministro === 'inventario') {
                $repuestoId = $requerimiento->repuesto_id ?: $r->validated('repuesto_id');
                if (! $repuestoId) throw ValidationException::withMessages(['repuestoId' => 'Selecciona el repuesto aprobado del inventario.']);
                $repuesto = RepuestoEloquentModel::whereKey($repuestoId)->where('estado', 'activo')->lockForUpdate()->firstOrFail();
                if ($requerimiento->repuesto_id && $requerimiento->repuesto_id !== $repuesto->id) throw ValidationException::withMessages(['repuestoId' => 'El repuesto no coincide con el requerimiento aprobado.']);
                $movimiento = $movimientos->registrar($repuesto->id, (string) $cantidad->negated(), 'salida', 'Uso real en orden '.$bloqueada->numero.($r->validated('observaciones') ? ': '.$r->validated('observaciones') : ''), $r->user()->id, $bloqueada->id, $repuesto->costo_referencia);
                $precio = (string) $repuesto->precio_venta;
                $codigo = $repuesto->codigo;
                $nombre = $repuesto->nombre;
                $unidad = $repuesto->unidad;
                if (! $requerimiento->repuesto_id) $requerimiento->update(['repuesto_id' => $repuesto->id, 'unidad_snapshot' => $repuesto->unidad, 'actualizado_por' => $r->user()->id]);
            } elseif (! $unidad) {
                throw ValidationException::withMessages(['requerimientoId' => 'El repuesto externo o suministrado por el cliente debe tener unidad registrada.']);
            }
            if ($requerimiento->fuente_suministro === 'cliente') $precio = '0.00';
            $uso = OrdenRepuestoEloquentModel::create(['orden_id' => $bloqueada->id, 'repuesto_id' => $repuesto?->id, 'requerimiento_id' => $requerimiento->id, 'cantidad' => (string) $cantidad, 'precio_unitario' => $precio, 'codigo_snapshot' => $codigo, 'nombre_snapshot' => $nombre, 'unidad_snapshot' => $unidad, 'fuente_suministro' => $requerimiento->fuente_suministro, 'facturable' => $facturable, 'visible_cliente' => true, 'movimiento_salida_id' => $movimiento?->id, 'registrado_por' => $r->user()->id]);
            if ($cantidad->isEqualTo($restante)) {
                $requerimiento->update(['estado' => 'utilizado', 'actualizado_por' => $r->user()->id]);
                DB::table('orden_repuesto_requerido_historial')->insert(['id' => (string) Str::uuid(), 'requerimiento_id' => $requerimiento->id, 'estado_anterior' => 'aprobado', 'estado_nuevo' => 'utilizado', 'cantidad' => $requerimiento->cantidad, 'motivo' => 'Cantidad aprobada utilizada completamente.', 'usuario_id' => $r->user()->id, 'created_at' => now()]);
            }
            return $uso;
        });
        $auditoria->registrar('orden.repuesto_usado', 'orden_repuesto', $uso->id, ['fuente' => $uso->fuente_suministro], $r);
        $historial->registrar($orden->vehiculo_id, 'orden.repuesto_usado', "Se registró un repuesto utilizado en {$orden->numero}.", ['orden_id' => $orden->id, 'uso_id' => $uso->id, 'fuente' => $uso->fuente_suministro], $r);
        return back()->with('success', $uso->fuente_suministro === 'inventario' ? 'Repuesto utilizado y descontado del inventario.' : 'Repuesto utilizado registrado sin afectar inventario interno.');
    }

    private function procesarReversionOrden(Request $r, OrdenRepuestoEloquentModel $uso, RegistrarMovimientoInventario $movimientos, CalculadorTotalOrden $calculador, RegistrarAuditoria $auditoria, RegistrarEventoVehiculo $historial): RedirectResponse
    {
        abort_unless($r->user()->can('repuestos.utilizar'), 403);
        $datos = $r->validate(['motivo' => ['required', 'string']]);
        DB::transaction(function () use ($r, $uso, $movimientos, $calculador, $datos) {
            $orden = OrdenTrabajoEloquentModel::whereKey($uso->orden_id)->lockForUpdate()->firstOrFail();
            $this->autorizarOrden($r, $orden);
            if (in_array($orden->estado, ['finalizada', 'lista_entrega', 'entregada', 'cancelada'], true)) throw ValidationException::withMessages(['movimiento' => 'La orden ya no admite devoluciones.']);
            if (DB::table('facturas_orden')->where('orden_id', $orden->id)->where('estado', 'emitida')->exists()) throw ValidationException::withMessages(['movimiento' => 'No se puede modificar una orden con factura vigente.']);
            $bloqueado = OrdenRepuestoEloquentModel::whereKey($uso->id)->lockForUpdate()->firstOrFail();
            if ($bloqueado->revertido_en) throw ValidationException::withMessages(['movimiento' => 'Este uso ya fue revertido.']);
            $resumen = $calculador->calcular($orden->id);
            $reduccion = BigDecimal::of((string) $bloqueado->cantidad)->multipliedBy(BigDecimal::of((string) $bloqueado->precio_unitario));
            if (BigDecimal::of($resumen['total'])->minus($reduccion)->isLessThan(BigDecimal::of($resumen['pagado']))) throw ValidationException::withMessages(['movimiento' => 'Primero anula los pagos que excederían el nuevo total.']);
            $reversion = null;
            if ($bloqueado->fuente_suministro === 'inventario') {
                $origen = MovimientoInventarioEloquentModel::whereKey($bloqueado->movimiento_salida_id)->lockForUpdate()->firstOrFail();
                $reversion = $movimientos->revertir($origen, $datos['motivo'], $r->user()->id);
            }
            $bloqueado->update(['movimiento_reversion_id' => $reversion?->id, 'revertido_en' => now(), 'revertido_por' => $r->user()->id]);
            $requerimiento = OrdenRepuestoRequeridoEloquentModel::whereKey($bloqueado->requerimiento_id)->lockForUpdate()->first();
            if ($requerimiento && $requerimiento->estado === 'utilizado') {
                $requerimiento->update(['estado' => 'aprobado', 'actualizado_por' => $r->user()->id]);
                DB::table('orden_repuesto_requerido_historial')->insert(['id' => (string) Str::uuid(), 'requerimiento_id' => $requerimiento->id, 'estado_anterior' => 'utilizado', 'estado_nuevo' => 'aprobado', 'cantidad' => $requerimiento->cantidad, 'motivo' => $datos['motivo'], 'usuario_id' => $r->user()->id, 'created_at' => now()]);
            }
        });
        $auditoria->registrar('orden.repuesto_revertido', 'orden_repuesto', $uso->id, ['motivo' => $datos['motivo']], $r);
        $orden = OrdenTrabajoEloquentModel::findOrFail($uso->orden_id);
        $historial->registrar($orden->vehiculo_id, 'orden.repuesto_revertido', "Se revirtió un repuesto en {$orden->numero}.", ['orden_id' => $orden->id, 'uso_id' => $uso->id], $r);
        return back()->with('success', $uso->fuente_suministro === 'inventario' ? 'Se creó el movimiento compensatorio de devolución.' : 'Se revirtió el registro sin afectar inventario interno.');
    }

    private function autorizarOrden(
        Request $r,
        OrdenTrabajoEloquentModel $o,
    ): void {
        app(AutorizarMecanicoOrden::class)->autorizar($r->user(), $o);
    }
    private function catalogosDatos(): array
    {
        return [
            "categorias" => CategoriaRepuestoEloquentModel::orderBy(
                "nombre",
            )->get(["id", "nombre", "descripcion", "estado"]),
            "proveedores" => ProveedorEloquentModel::orderBy("nombre")->get([
                "id",
                "documento",
                "nombre",
                "contacto",
                "telefono",
                "email",
                "estado",
            ]),
            "repuestosMovimiento" => RepuestoEloquentModel::where(
                "estado",
                "activo",
            )
                ->orderBy("nombre")
                ->get(["id", "codigo", "nombre", "unidad", "stock_actual"]),
        ];
    }
    private function validarProveedor(
        Request $r,
        ?ProveedorEloquentModel $proveedor = null,
    ): array {
        $telefono = trim((string) $r->input("telefono"));
        $r->merge([
            "documento" => mb_strtoupper(trim((string) $r->input("documento"))),
            "nombre" => trim((string) $r->input("nombre")),
            "contacto" => $r->filled("contacto")
                ? trim((string) $r->input("contacto"))
                : null,
            "telefono" =>
                $telefono !== ""
                    ? (str_starts_with($telefono, "+")
                        ? "+" . preg_replace("/\D/", "", $telefono)
                        : preg_replace("/\D/", "", $telefono))
                    : null,
            "email" => $r->filled("email")
                ? mb_strtolower(trim((string) $r->input("email")))
                : null,
        ]);
        return $r->validate(
            [
                "documento" => [
                    "required",
                    "string",
                    "max:40",
                    Rule::unique("proveedores")->ignore($proveedor?->id),
                ],
                "nombre" => ["required", "string", "max:180"],
                "contacto" => ["nullable", "string", "max:120"],
                "telefono" => [
                    "nullable",
                    "string",
                    "max:13",
                    new TelefonoEcuatoriano(),
                ],
                "email" => ["nullable", "email:rfc", "max:254"],
            ],
            [
                "documento.required" =>
                    "El documento o NIT del proveedor es obligatorio.",
                "nombre.required" => "El nombre del proveedor es obligatorio.",
                "email.email" => "Ingresa un correo electrónico válido.",
            ],
        );
    }
    private function cambiarEstadoCatalogo(
        Request $r,
        $modelo,
        RegistrarAuditoria $a,
        string $tipo,
    ): void {
        abort_unless($r->user()->can("inventario.gestionar"), 403);
        $d = $r->validate([
            "estado" => "required|in:activo,inactivo,archivado",
        ]);
        $anterior = $modelo->estado;
        $modelo->update([
            "estado" => $d["estado"],
            "actualizado_por" => $r->user()->id,
        ]);
        $a->registrar(
            $tipo . ".estado_cambiado",
            $tipo,
            $modelo->id,
            ["anterior" => $anterior, "nuevo" => $modelo->estado],
            $r,
        );
    }
}
