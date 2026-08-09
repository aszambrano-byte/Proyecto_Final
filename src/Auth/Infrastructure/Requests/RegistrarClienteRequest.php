<?php

namespace Src\Auth\Infrastructure\Requests;

use App\Rules\DocumentoColombiano;
use App\Rules\TelefonoEcuatoriano;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegistrarClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
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
        return [
            'tipo_documento' => ['required', Rule::in(['CC', 'CE', 'NIT', 'PASAPORTE'])],
            'numero_documento' => ['required', 'string', new DocumentoColombiano((string) $this->input('tipo_documento')), Rule::unique('clientes', 'numero_documento')],
            'razon_social' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:13', new TelefonoEcuatoriano],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users', 'email'), Rule::unique('clientes', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_documento.unique' => 'Este número de documento ya está registrado.',
            'email.unique' => 'Este correo ya está registrado.',
            'password.mixed' => 'La contraseña debe incluir mayúsculas y minúsculas.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
        ];
    }
}
