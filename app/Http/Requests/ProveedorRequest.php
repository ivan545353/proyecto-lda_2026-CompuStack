<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use App\Support\ReglasFiscales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de proveedor. Sirve para el alta y para la edición;
 * `$this->route('proveedor')` distingue una de otra.
 *
 * Dos reglas condicionales, que son lo propio de este formulario:
 *
 *   1. El correo es obligatorio si los pedidos se mandan por correo. Sin
 *      dirección, la pantalla ofrecería «mandale el PDF a ___» sin tener a
 *      dónde: es el mismo defecto de los pendientes de usabilidad #1 a #3,
 *      ofrecer un camino que el sistema no puede completar.
 *   2. La dirección del portal es obligatoria con ese canal y RECHAZADA con
 *      cualquier otro.
 *
 * Métodos:
 *   authorize()             true; el permiso ya lo exige la ruta
 *   prepareForValidation()  normaliza el CUIT, el correo, el checkbox y los vacíos
 *   rules()                 las reglas
 *   messages() / attributes()
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
            // El CUIT se lee de una factura escrito 30-71234567-8 y se guarda en
            // once dígitos, igual que clientes.nro_doc. normalizar() saca los
            // separadores y NADA más: si hay letras siguen ahí y la regla las
            // rechaza con un mensaje que lo dice. Limpiarlas sería M-31, donde
            // "30-ABC" se convertía en "30" y se guardaba igual.
            'cuit' => ReglasFiscales::normalizar($this->input('cuit')),

            'email' => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),

            // El checkbox sin marcar no viaja en el POST: sin esto, desactivar un
            // proveedor desde el formulario sería imposible.
            'activo' => $this->boolean('activo'),
        ]);

        // Un campo de texto vacío llega como cadena vacía, y un formulario HTML
        // no tiene forma de expresar "null". Es normalización de FORMATO, no de
        // contenido: "sin cargar" y "" son el mismo hecho, y null es lo único
        // que `nullable` y `prohibited_unless` entienden como ausencia. Sin
        // esto, un proveedor de canal manual con el campo del portal vacío
        // fallaría la regla `url` y el usuario no entendería por qué.
        foreach (['email', 'telefono', 'contacto', 'portal_url'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }
    }

    public function rules(): array
    {
        $proveedor = $this->route('proveedor');

        return [
            'razon_social' => ['required', 'string', 'min:2', 'max:150'],

            // La columna es NOT NULL UNIQUE: un proveedor al que le compramos nos
            // factura, y el CUIT es cómo se lo identifica. La regla dice lo mismo
            // que la base antes de llegar a ella, para que el usuario reciba un
            // mensaje y no un error de integridad.
            'cuit' => [
                'required', 'digits:11',
                Rule::unique('proveedores', 'cuit')->ignore($proveedor?->id),
            ],

            'email'    => ['required_if:canal_pedido,email', 'nullable', 'email', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'contacto' => ['nullable', 'string', 'max:100'],

            // La lista sale de la constante del modelo, así que el Form Request,
            // el scope del listado y el selector de la vista comparan exactamente
            // los mismos valores.
            'canal_pedido' => ['required', Rule::in(array_keys(Proveedor::CANALES))],

            // Vaciarla en silencio al cambiar el canal sería corregir en lugar de
            // rechazar. La vista deshabilita el campo cuando no corresponde —un
            // campo deshabilitado no viaja—, y si viaja igual (sin JavaScript, o
            // en una petición armada a mano) el mensaje explica por qué no va.
            'portal_url' => [
                'required_if:canal_pedido,portal_externo',
                'prohibited_unless:canal_pedido,portal_externo',
                'nullable', 'url', 'max:255',
            ],

            // 0 significa "sin informar", y la vista lo muestra así en lugar de
            // "0 días". El formulario trae el 0 cargado en el alta: el valor por
            // omisión lo pone la pantalla y no el servidor, así que lo que se
            // guarda es exactamente lo que el usuario vio.
            'plazo_entrega_dias' => ['required', 'integer', 'min:0', 'max:365'],

            'activo' => ['required', 'boolean'],

            // La cascada la pide el usuario, nunca es un efecto automático.
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