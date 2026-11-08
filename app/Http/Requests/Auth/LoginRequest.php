<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
/**
 * Validación del formulario de acceso.
 *
 * Sólo verifica la forma de los datos: que vengan y que el correo parezca un
 * correo. Comprobar que las credenciales sean correctas es otra cosa y ocurre
 * en el controlador.
 *
 * Laravel lo resuelve antes de ejecutar el método del controlador: si las
 * reglas fallan, el método nunca corre.
 *
 * Métodos:
 *   authorize()  true; cualquiera puede intentar ingresar
 *   rules()      reglas de los campos
 *   messages()   mensajes en español
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:150'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'Ingresa tu correo electrónico.',
            'email.email'       => 'El formato del correo electrónico no es válido.',
            'password.required' => 'Ingresa tu contraseña.',
        ];
    }
}