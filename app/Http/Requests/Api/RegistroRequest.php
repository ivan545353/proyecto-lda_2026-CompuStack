<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * El alta de una cuenta de la tienda (docs/api.md, `POST /registro`).
 *
 * La contraseña lleva la misma regla que `CambiarPasswordRequest`: una cuenta de la tienda no
 * es más débil que una de gestión. Los mensajes van acá y no en lang/ (ver `PaginadoRequest`).
 *
 * Métodos:
 *   razonSocial()  «nombre apellido», que es la razón social del cliente que se crea
 */
class RegistroRequest extends FormRequest
{
    /** Largo de `clientes.razon_social`. */
    private const RAZON_SOCIAL_MAX = 150;

    public function authorize(): bool
    {
        return true;
    }

    /** El correo se compara sin mayúsculas ni espacios, como en la gestión y en el límite de intentos. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'nombre'   => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** Juntos tienen que entrar en la razón social: se rechaza, no se recorta (M-31, HL-53). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['nombre', 'apellido'])) {
                    return;
                }

                if (mb_strlen($this->razonSocial()) > self::RAZON_SOCIAL_MAX) {
                    $validator->errors()->add('apellido',
                        'Entre nombre y apellido admiten hasta '.self::RAZON_SOCIAL_MAX.' caracteres.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'    => 'Escribí tu nombre.',
            'nombre.string'      => 'El nombre tiene que ser un texto.',
            'nombre.max'         => 'El nombre admite hasta :max caracteres.',
            'apellido.required'  => 'Escribí tu apellido.',
            'apellido.string'    => 'El apellido tiene que ser un texto.',
            'apellido.max'       => 'El apellido admite hasta :max caracteres.',
            'email.required'     => 'Escribí tu correo.',
            'email.string'       => 'El correo tiene que ser un texto.',
            'email.email'        => 'Ese correo no es válido.',
            'email.max'          => 'El correo admite hasta :max caracteres.',
            'email.unique'       => 'Ya hay una cuenta con ese correo.',
            'password.required'  => 'Escribí una contraseña.',
            'password.string'    => 'La contraseña tiene que ser un texto.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
            'password.min'       => 'La contraseña tiene que tener al menos :min caracteres.',
            'password.letters'   => 'La contraseña tiene que tener al menos una letra.',
            'password.numbers'   => 'La contraseña tiene que tener al menos un número.',
            'telefono.string'    => 'El teléfono tiene que ser un texto.',
            'telefono.max'       => 'El teléfono admite hasta :max caracteres.',
        ];
    }

    public function razonSocial(): string
    {
        return trim($this->input('nombre').' '.$this->input('apellido'));
    }
}
