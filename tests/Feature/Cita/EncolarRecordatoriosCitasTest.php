<?php

declare(strict_types=1);

namespace Tests\Feature\Cita;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Src\Cita\Application\Services\EncolarRecordatoriosCitas;
use Tests\TestCase;

final class EncolarRecordatoriosCitasTest extends TestCase
{
    private bool $usaPostgres = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            if (! in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
                $this->markTestSkipped('La integración requiere PDO PostgreSQL o SQLite.');
            }

            $basePruebas = getenv('PGSQL_INTEGRATION_DATABASE');
            if (! $basePruebas) {
                $this->markTestSkipped('Define PGSQL_INTEGRATION_DATABASE para ejecutar esta integración en una transacción revertida.');
            }

            config()->set('database.default', 'pgsql');
            config()->set('database.connections.pgsql.database', $basePruebas);
            DB::purge('pgsql');
            DB::connection('pgsql')->beginTransaction();
            $this->usaPostgres = true;

            return;
        }

        Schema::create('clientes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->timestamps();
        });
        Schema::create('citas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cliente_id');
            $table->string('estado');
            $table->timestampTz('inicio');
            $table->timestampTz('fin');
            $table->timestamps();
        });
        Schema::create('cita_recordatorio_entregas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cita_id');
            $table->timestampTz('inicio_programado');
            $table->string('canal');
            $table->string('destinatario');
            $table->timestampTz('encolado_en');
            $table->timestampTz('intentado_en')->nullable();
            $table->timestampTz('invalidado_en')->nullable();
            $table->timestamps();
            $table->unique(['cita_id', 'inicio_programado', 'canal']);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        if ($this->usaPostgres) {
            DB::connection('pgsql')->rollBack();
            DB::purge('pgsql');
        } elseif (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            Schema::dropIfExists('cita_recordatorio_entregas');
            Schema::dropIfExists('citas');
            Schema::dropIfExists('clientes');
        }

        parent::tearDown();
    }

    public function test_es_idempotente_y_una_reprogramacion_crea_una_entrega_nueva(): void
    {
        Notification::fake();
        config()->set('autofix.appointment_reminders.enabled', true);
        config()->set('autofix.appointment_reminders.window_minutes', 120);
        $ahora = CarbonImmutable::parse('2099-08-09 08:00:00', 'America/Bogota');
        CarbonImmutable::setTestNow($ahora);

        DB::table('clientes')->insert([
            'id' => '10000000-0000-4000-8000-000000000001',
            ...($this->usaPostgres ? [
                'tipo_documento' => 'CC',
                'numero_documento' => '1000000001',
                'razon_social' => 'Cliente de recordatorios',
                'direccion' => 'Dirección de prueba',
                'telefono' => '0990000001',
            ] : []),
            'email' => 'cliente@example.com',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        if ($this->usaPostgres) {
            DB::table('vehiculos')->insert([
                'id' => '30000000-0000-4000-8000-000000000001',
                'cliente_id' => '10000000-0000-4000-8000-000000000001',
                'placa' => 'TST001',
                'placa_normalizada' => 'TST001',
                'marca' => 'Marca prueba',
                'modelo' => 'Modelo prueba',
                'anio' => 2026,
                'kilometraje' => 100,
                'combustible' => 'gasolina',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
        DB::table('citas')->insert([
            'id' => '20000000-0000-4000-8000-000000000001',
            'cliente_id' => '10000000-0000-4000-8000-000000000001',
            ...($this->usaPostgres ? [
                'numero' => 'CIT-TEST-REMINDER',
                'vehiculo_id' => '30000000-0000-4000-8000-000000000001',
                'motivo' => 'Prueba de recordatorio',
            ] : []),
            'estado' => 'confirmada',
            'inicio' => $ahora->addHour(),
            'fin' => $ahora->addHours(2),
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $servicio = app(EncolarRecordatoriosCitas::class);

        $this->assertSame(1, $servicio->ejecutar());
        $this->assertSame(0, $servicio->ejecutar());
        $this->assertSame(1, DB::table('cita_recordatorio_entregas')->where('cita_id', '20000000-0000-4000-8000-000000000001')->count());

        DB::table('citas')->where('id', '20000000-0000-4000-8000-000000000001')->update([
            'estado' => 'reprogramada',
            'inicio' => $ahora->addMinutes(90),
            'fin' => $ahora->addMinutes(150),
        ]);

        $this->assertSame(1, $servicio->ejecutar());
        $this->assertSame(2, DB::table('cita_recordatorio_entregas')->where('cita_id', '20000000-0000-4000-8000-000000000001')->count());
    }
}
