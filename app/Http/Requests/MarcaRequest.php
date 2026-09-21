<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de marca. Sirve para el alta y para la edición.
 *
 * Cierra el hallazgo M-31. Los setters del sistema original no validaban:
 * corregían. `setNombre()` convertía en cadena vacía todo nombre de más de 100
 * caracteres, y `setCorreo()` hacía lo mismo con un mail inválido; el servicio
 * después informaba "el correo es obligatorio", un mensaje que no describe el
 * problema. Una regla de Form Request rechaza la petición, conserva lo que el
 * usuario escribió y explica qué está mal.
 *
 * Métodos:
 *   authorize()             true; el permiso ya lo exige la ruta
 *   prepareForValidation()  normaliza espacios y el checkbox
 *   rules()                 las reglas
 *   messages()              los mensajes, en español
 */
class MarcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige marca.crear o marca.editar
    }

    /**
     * Normalizar no es corregir: recortar espacios de los bordes no cambia lo
     * que el usuario quiso escribir. Un nombre de sólo espacios queda en cadena
     * vacía y lo rechaza `required` con su mensaje, que es justo lo contrario
     * de lo que hacía el setter original.
     *
     * El checkbox sin marcar no viaja en el POST. Sin esta línea, desactivar
     * una marca sería imposible: la ausencia del campo se leería como "no lo
     * cambies".
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => trim((string) $this->input('nombre')),
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $marca = $this->route('marca');

        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('marcas', 'nombre')->ignore($marca?->id),
            ],

            // Sin SVG a propósito: un SVG es XML y puede contener <script>.
            // Servido desde el mismo origen que la aplicación, un logo subido
            // por un administrativo se convierte en XSS almacenado sobre la
            // sesión de todos los demás. Los formatos de mapa de bits no
            // ejecutan nada.
            'logo'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:512'],
            'quitar_logo' => ['nullable', 'boolean'],

            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'La marca necesita un nombre.',
            'nombre.min'      => 'El nombre debe tener al menos 2 caracteres.',
            'nombre.max'      => 'El nombre no puede superar los 100 caracteres.',
            'nombre.unique'   => 'Ya existe una marca con ese nombre.',
            'logo.image'      => 'El logo debe ser una imagen.',
            'logo.mimes'      => 'Formatos aceptados: JPG, PNG o WEBP.',
            'logo.max'        => 'El logo no puede superar los 512 KB.',
        ];
    }
}