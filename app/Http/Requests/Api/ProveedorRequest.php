<?php

namespace App\Http\Requests\Api;

use App\Models\Proveedor;
use App\Support\ReglasFiscales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de un proveedor (tramo E). Rechaza, no corrige (M-31): `activo` viaja
 * siempre, porque la API no adivina una casilla ausente. Normaliza sólo el formato (CUIT sin
 * separadores, correo en minúscula).
 *
 * Dos reglas condicionales: el correo es obligatorio si los pedidos van por correo, y la dirección
 * del portal es obligatoria con ese canal y rechazada con cualquier otro.
 *
 * Métodos:
 *   prepareForValidation()  normaliza el CUIT y el correo
 *   rules()                 las reglas
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class ProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige proveedor.crear o proveedor.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // normalizar() saca los separadores y nada más: «30-ABC» sigue con letras y la regla lo rechaza.
            'cuit'  => ReglasFiscales::normalizar($this->input('cuit')),
            'email' => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        return [
            'razon_social' => ['required', 'string', 'min:2', 'max:150'],

            'cuit' => [
                'required', 'digits:11',
                Rule::unique('proveedores', 'cuit')->ignore($this->route('proveedor')?->id),
            ],

            'email'    => ['required_if:canal_pedido,email', 'nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'contacto' => ['nullable', 'string', 'max:100'],

            'canal_pedido' => ['required', Rule::in(array_keys(Proveedor::CANALES))],

            // Vaciarla en silencio al cambiar el canal sería corregir en lugar de rechazar.
            'portal_url' => [
                'required_if:canal_pedido,portal_externo',
                'prohibited_unless:canal_pedido,portal_externo',
                'nullable', 'url', 'max:255',
            ],

            // 0 significa «sin informar».
            'plazo_entrega_dias' => ['required', 'integer', 'min:0', 'max:365'],

            'activo' => ['required', 'boolean'],

            // La cascada la pide quien edita, nunca es un efecto automático.
            'desactivar_productos' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'razon_social.required' => 'El proveedor necesita un nombre o razón social.',
            'razon_social.min'      => 'El nombre debe tener al menos 2 caracteres.',
            'razon_social.max'      => 'El nombre no puede superar los 150 caracteres.',

            'cuit.required' => 'Escribí el CUIT del proveedor.',
            'cuit.digits'   => 'El CUIT tiene 11 dígitos. Podés escribirlo con guiones: se guarda sin ellos.',
            'cuit.unique'   => 'Ya hay un proveedor registrado con ese CUIT.',

            'email.required_if' => 'Si los pedidos se le mandan por correo, hace falta la dirección a la que mandarlos.',
            'email.email'       => 'Escribí un correo válido.',

            'canal_pedido.required' => 'Elegí cómo se le hacen los pedidos a este proveedor.',
            'canal_pedido.in'       => 'Ese canal de pedido no existe.',

            'portal_url.required_if'       => 'Si los pedidos se cargan en su portal, hace falta la dirección del portal.',
            'portal_url.prohibited_unless' => 'La dirección del portal sólo corresponde si los pedidos se cargan en el portal del proveedor. Cambiá el canal o borrá la dirección.',
            'portal_url.url'               => 'La dirección del portal tiene que ser completa, empezando con https://',

            'plazo_entrega_dias.required' => 'Indicá en cuántos días entrega. Poné 0 si todavía no lo sabés.',
            'plazo_entrega_dias.integer'  => 'El plazo de entrega se escribe en días, en número entero.',
            'plazo_entrega_dias.min'      => 'El plazo de entrega no puede ser negativo.',
            'plazo_entrega_dias.max'      => 'Un plazo de más de 365 días probablemente sea un error de tipeo.',

            'activo.required' => 'Indicá si el proveedor queda activo.',
            '*.boolean'       => 'Esa opción es sí o no.',
        ];
    }

    public function attributes(): array
    {
        return [
            'razon_social'       => 'nombre o razón social',
            'cuit'               => 'CUIT',
            'email'              => 'correo',
            'telefono'           => 'teléfono',
            'contacto'           => 'persona de contacto',
            'canal_pedido'       => 'canal de pedido',
            'portal_url'         => 'dirección del portal',
            'plazo_entrega_dias' => 'plazo de entrega',
            'activo'             => 'estado',
        ];
    }
}
