<?php

namespace Src\Cliente\Infrastructure\Requests;

use App\Rules\DocumentoColombiano;
use App\Rules\TelefonoEcuatoriano;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clientes.editar') ?? false;
    }

    protected function prepareForValidation()
    {
        $tipo = mb_strtoupper(trim((string) $this->input('tipoDocumento')));
        $telefono = trim((string) $this->input('telefono'));
        $this->merge([
            'tipo_documento' => $tipo,
            'numero_documento' => mb_strtoupper(preg_replace($tipo === 'PASAPORTE' ? '/[^A-Z0-9]/i' : '/\D/', '', (string) $this->input('numeroDocumento'))),
            'razon_social' => trim((string) $this->input('razonSocial')),
            'direccion' => trim((string) $this->input('direccion')),
            'telefono' => str_starts_with($telefono, '+') ? '+'.preg_replace('/\D/', '', $telefono) : preg_replace('/\D/', '', $telefono),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        // Obtener el ID desde la ruta (puede ser 'id' o 'cliente' dependiendo de si es web o API)
        $clienteId = $this->route('id') ?? $this->route('cliente');

        return [
            'tipo_documento' => ['required', Rule::in(['CC', 'CE', 'NIT', 'PASAPORTE'])],
            'numero_documento' => ['required', 'string', new DocumentoColombiano((string) $this->input('tipo_documento')), Rule::unique('clientes', 'numero_documento')->ignore($clienteId)],
            'razon_social' => 'required|string|max:255',
            'direccion' => 'required|string|max:255',
            'telefono' => ['required', 'string', 'max:13', new TelefonoEcuatoriano],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('clientes', 'email')->ignore($clienteId)],
        ];
    }

    public function attributes(): array
    {
        return [
            'tipo_documento' => 'tipo de documento',
            'numero_documento' => 'número de documento',
            'razon_social' => 'razón social',
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
            'email' => 'email',
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento.required' => 'El tipo de documento es obligatorio',
            'tipo_documento.in' => 'Selecciona CC, CE, NIT o PASAPORTE como tipo de documento',
            'numero_documento.required' => 'El número de documento es obligatorio',
            'numero_documento.unique' => 'Este número de documento ya está registrado',
            'razon_social.required' => 'La razón social es obligatoria',
            'direccion.required' => 'La dirección es obligatoria',
            'telefono.required' => 'El teléfono es obligatorio',
            'email.email' => 'El email debe ser válido',
            'email.required' => 'El email es obligatorio',
            'email.unique' => 'Este email ya está registrado',
            'email.max' => 'El email no puede superar 254 caracteres',
        ];
    }
}
