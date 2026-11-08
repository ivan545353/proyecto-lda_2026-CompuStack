<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validación del formulario que abre la persona con el enlace.
 *
 * No pide la contraseña actual, a diferencia de CambiarPasswordRequest: acá el
 * caso de uso es justamente que no se la acuerda. La prueba de identidad es el
 * token, que el broker verifica hasheado y con vencimiento.
 *
 * El `token` y el `email` viajan en campos ocultos porque vienen del enlace. No
 * son "datos del usuario": son la credencial de un solo uso.
 */
class RestablecerPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // el token es la autorización, y lo valida el broker
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required', 'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required'  => 'Escribí la contraseña que querés usar.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
        ];
    }

    public function attributes(): array
    {
        return ['password' => 'contraseña', 'email' => 'correo'];
    }
}