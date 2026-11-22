<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El nombre de la cuenta de la tienda que el operador le da a un cliente del mostrador (D-26, tramo E).
 *
 * El correo no viaja: es el de la ficha, que la persona ya dio en el mostrador. La ficha sólo tiene
 * razón social, y una cuenta pide nombre y apellido por separado.
 *
 * Métodos:
 *   rules()     nombre y apellido, obligatorios
 *   messages()  en español y acá (la API no depende de APP_LOCALE)
 */
class AccesoTiendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige cliente.dar_acceso_tienda
    }

    public function rules(): array
    {
        return [
            'nombre'   => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'   => 'Escribí el nombre de la persona que va a usar la cuenta.',
            'apellido.required' => 'Escribí el apellido de la persona que va a usar la cuenta.',
            'nombre.max'        => 'El nombre puede tener hasta 100 caracteres.',
            'apellido.max'      => 'El apellido puede tener hasta 100 caracteres.',
        ];
    }
}
