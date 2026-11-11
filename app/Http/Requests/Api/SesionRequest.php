<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * El inicio de sesión de la tienda (docs/api.md, `POST /sesion`). Sólo exige que vengan los
 * dos datos: si son correctos lo decide `CredencialService::iniciar()`.
 */
class SesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** El correo se compara como se guardó en el registro: minúsculas y sin espacios. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Escribí tu correo.',
            'email.string'      => 'El correo tiene que ser un texto.',
            'email.max'         => 'El correo admite hasta :max caracteres.',
            'password.required' => 'Escribí tu contraseña.',
            'password.string'   => 'La contraseña tiene que ser un texto.',
        ];
    }
}
