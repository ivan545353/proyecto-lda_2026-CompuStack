<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de una marca (tramo D). Rechaza, no corrige (M-31): `activo` viaja
 * siempre, porque la API no adivina un checkbox ausente. El logo llega en multipart; en la
 * edición, por `POST` con `_method=PATCH`, porque PHP no lee archivos de un `PATCH` (HL-110).
 *
 * Métodos:
 *   rules()     las reglas
 *   messages()  los mensajes, en español y acá (la API no depende de APP_LOCALE)
 */
class MarcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige marca.crear o marca.editar
    }

    public function rules(): array
    {
        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:100',
                Rule::unique('marcas', 'nombre')->ignore($this->route('marca')?->id),
            ],
            // Sin SVG a propósito: un SVG es XML y puede llevar <script> (XSS almacenado).
            'logo'                 => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:512'],
            'quitar_logo'          => ['nullable', 'boolean'],
            'activo'               => ['required', 'boolean'],
            'desactivar_productos' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'  => 'La marca necesita un nombre.',
            'nombre.string'    => 'La marca necesita un nombre.',
            'nombre.min'       => 'El nombre debe tener al menos 2 caracteres.',
            'nombre.max'       => 'El nombre no puede superar los 100 caracteres.',
            'nombre.unique'    => 'Ya existe una marca con ese nombre.',
            'logo.image'       => 'El logo debe ser una imagen.',
            'logo.mimes'       => 'Formatos aceptados: JPG, PNG o WEBP.',
            'logo.max'         => 'El logo no puede superar los 512 KB.',
            'activo.required'  => 'Indicá si la marca queda activa.',
            'activo.boolean'   => 'El estado de la marca es sí o no.',
            '*.boolean'        => 'Esa opción es sí o no.',
        ];
    }
}
