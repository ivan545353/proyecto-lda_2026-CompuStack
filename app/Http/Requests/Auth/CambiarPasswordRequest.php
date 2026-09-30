<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validación del cambio de contraseña propia.
 *
 * `resetPass` bloqueaba el login y no existía ninguna
 *  pantalla para restablecer la clave, así que la
 * cuenta quedaba en un estado del que no se salía.
 *
 * Pide la contraseña actual aunque la sesión ya esté abierta. Estar autenticado
 * prueba que alguien abrió esa sesión, no que sea el dueño de la cuenta: una
 * máquina desatendida en el mostrador alcanza para que un tercero se apropie de
 * un usuario. La regla `current_password` la verifica contra el guard.
 *
 * Métodos:
 *   authorize()  true; no lleva permiso, y eso es deliberado (ver las rutas)
 *   rules()      las tres reglas
 *   messages() / attributes()
 */
class CambiarPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Sin permiso a propósito: cambiar la propia contraseña es una
        // capacidad de toda cuenta, no un privilegio que concede un rol. Si
        // dependiera de un permiso, un rol al que se le olvidara asignárselo
        // dejaría a su gente sin poder cambiarla: M-21 otra vez, esta vez por
        // configuración en lugar de por código.
        return true;
    }

    public function rules(): array
    {
        return [
            // `bail` para que un error acá no venga acompañado del de `different`,
            // que sólo confundiría.
            'password_actual' => ['bail', 'required', 'current_password'],

            'password' => [
                'required', 'confirmed',
                Password::min(8)->letters()->numbers(),
                // Guardar la misma contraseña es una operación que el usuario
                // cree que hizo algo. Se rechaza y se le dice por qué.
                'different:password_actual',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'password_actual.required'        => 'Escribí tu contraseña actual.',
            'password_actual.current_password'=> 'Esa no es tu contraseña actual.',
            'password.required'  => 'Escribí la contraseña nueva.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ];
    }

    public function attributes(): array
    {
        return [
            'password_actual' => 'contraseña actual',
            'password'        => 'contraseña nueva',
        ];
    }
}