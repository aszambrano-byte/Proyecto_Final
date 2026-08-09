<?php

namespace Src\Taller\Infrastructure\Requests;

use App\Rules\DocumentoColombiano;
use App\Rules\TelefonoEcuatoriano;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Src\Auth\Infrastructure\Models\UserEloquentModel;

class GuardarMecanicoRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('mecanicos.gestionar') ?? false; }

    protected function prepareForValidation(): void
    {
        $tipo = mb_strtoupper(trim((string) $this->input('tipoDocumento')));
        $telefono = trim((string) $this->input('telefono'));
        $this->merge([
            'usuario_id' => $this->input('usuarioId') ?: null,
            'tipo_documento' => $tipo,
            'numero_documento' => mb_strtoupper(preg_replace($tipo === 'PASAPORTE' ? '/[^A-Z0-9]/i' : '/\D/', '', (string) $this->input('numeroDocumento'))),
            'nombres' => trim((string) $this->input('nombres')),
            'apellidos' => trim((string) $this->input('apellidos')),
            'telefono' => str_starts_with($telefono, '+') ? '+'.preg_replace('/\D/', '', $telefono) : preg_replace('/\D/', '', $telefono),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'fecha_ingreso' => $this->input('fechaIngreso') ?: null,
            'especialidad_ids' => $this->input('especialidadIds', []),
            'horarios' => $this->input('horarios', []),
        ]);
    }

    public function rules(): array
    {
        $mecanico = $this->route('mecanico');
        $id = is_object($mecanico) ? $mecanico->getKey() : $mecanico;
        return [
            'usuario_id' => ['nullable', 'uuid', Rule::exists('users', 'id'), Rule::unique('mecanicos', 'usuario_id')->ignore($id)],
            'tipo_documento' => ['required', Rule::in(['CC', 'CE', 'PASAPORTE'])],
            'numero_documento' => ['required', 'string', new DocumentoColombiano((string) $this->input('tipo_documento')), Rule::unique('mecanicos')->ignore($id)],
            'nombres' => ['required', 'string', 'max:120'], 'apellidos' => ['required', 'string', 'max:120'],
            'telefono' => ['required', 'string', 'max:13', new TelefonoEcuatoriano],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('mecanicos')->ignore($id)],
            'fecha_ingreso' => ['nullable', 'date', 'before_or_equal:today'],
            'especialidad_ids' => ['required', 'array', 'min:1'],
            'especialidad_ids.*' => ['uuid', Rule::exists('especialidades', 'id')->where('estado', 'activo'), 'distinct'],
            'horarios' => ['required', 'array', 'min:1'],
            'horarios.*.diaSemana' => ['required', 'integer', 'between:1,7'],
            'horarios.*.horaInicio' => ['required', 'date_format:H:i'],
            'horarios.*.horaFin' => ['required', 'date_format:H:i', 'after:horarios.*.horaInicio'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $usuarioId = $this->input('usuario_id');
            if ($usuarioId && ! UserEloquentModel::whereKey($usuarioId)->where('activo', true)->role('Mecánico')->exists()) {
                $validator->errors()->add('usuarioId', 'La cuenta vinculada debe estar activa y tener el rol Mecánico.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'Selecciona el tipo de documento del mecánico.',
            'tipo_documento.in' => 'Selecciona CC, CE o PASAPORTE como tipo de documento.',
            'numero_documento.required' => 'El número de documento es obligatorio.',
            'numero_documento.unique' => 'Este número de documento ya está registrado.',
            'nombres.required' => 'Los nombres son obligatorios.',
            'apellidos.required' => 'Los apellidos son obligatorios.',
            'telefono.required' => 'El teléfono es obligatorio.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'especialidad_ids.required' => 'Selecciona al menos una especialidad.',
            'horarios.required' => 'Configura al menos un día de disponibilidad.',
        ];
    }
}
