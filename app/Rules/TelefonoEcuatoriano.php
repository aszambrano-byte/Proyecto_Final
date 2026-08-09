<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TelefonoEcuatoriano implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^(?:0(?:9\d{8}|[2-7]\d{7})|\+593(?:9\d{8}|[2-7]\d{7}))$/', (string) $value)) {
            $fail('Ingresa un teléfono ecuatoriano válido, por ejemplo 0991234567, o usa el prefijo +593.');
        }
    }
}
