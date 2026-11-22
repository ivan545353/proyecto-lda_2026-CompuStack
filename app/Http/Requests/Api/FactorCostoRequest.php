<?php

namespace App\Http\Requests\Api;

use App\Models\FactorCosto;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de un factor de costo (tramo F). Rechaza, no corrige (M-31): sólo normaliza el
 * formato del porcentaje; `orden` y `activo` viajan siempre.
 *
 * Métodos:
 *   prepareForValidation()  porcentaje en formato argentino
 *   rules()                 reglas; el tope depende del modo
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class FactorCostoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['porcentaje' => Importe::normalizar($this->input('porcentaje'))]);
    }

    public function rules(): array
    {
        $factor = $this->route('factor');

        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('factores_costo', 'nombre')->ignore($factor?->id),
            ],

            'modo' => ['required', Rule::in(array_keys(FactorCosto::MODOS))],

            // gt:0: un factor en cero no mueve el precio; para no aplicarlo está `activo`. Un recupero
            // divide por (1 − %), así que con 100 o más no hay precio posible.
            'porcentaje' => [
                'required', 'numeric', 'decimal:0,3', 'gt:0',
                Rule::when($this->input('modo') === 'recupero', ['max:99.999'], ['max:999.999']),
            ],

            'orden'  => ['required', 'integer', 'min:0', 'max:9999'],
            'activo' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required'     => 'El factor necesita un nombre.',
            'nombre.min'          => 'El nombre tiene que tener al menos :min letras.',
            'nombre.max'          => 'El nombre puede tener hasta :max letras.',
            'nombre.unique'       => 'Ya hay un factor con ese nombre.',
            'modo.required'       => 'Elegí si es un recargo sobre el costo o un recupero sobre la venta.',
            'modo.in'             => 'Elegí si es un recargo sobre el costo o un recupero sobre la venta.',
            'porcentaje.required' => 'Escribí el porcentaje.',
            'porcentaje.numeric'  => 'Escribí el porcentaje como 3 o 2,375.',
            'porcentaje.decimal'  => 'El porcentaje puede tener hasta tres decimales.',
            'porcentaje.gt'       => 'Un factor en cero no mueve el precio. Si no querés aplicarlo, desactivalo.',
            'porcentaje.max'      => 'Un recupero del 100 % o más no tiene precio que lo cubra.',
            'orden.required'      => 'Indicá el orden.',
            'orden.integer'       => 'El orden es un número entero.',
            'orden.min'           => 'El orden no puede ser negativo.',
            'orden.max'           => 'El orden puede llegar hasta :max.',
            'activo.required'     => 'Indicá si el factor está activo.',
            'activo.boolean'      => 'Indicá si el factor está activo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre'     => 'nombre',
            'modo'       => 'modo',
            'porcentaje' => 'porcentaje',
            'orden'      => 'orden',
            'activo'     => 'estado',
        ];
    }
}
