<?php

namespace App\Http\Requests;

use App\Models\FactorCosto;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de un factor de costo. Sirve para el alta y la edición.
 *
 * Métodos:
 *   prepareForValidation()  porcentaje en formato argentino
 *   rules()                 reglas; el tope depende del modo
 *   messages() / attributes()
 */
class FactorCostoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'porcentaje' => Importe::normalizar($this->input('porcentaje')),
            'orden'      => $this->input('orden') ?? 0,
            'activo'     => $this->boolean('activo'),
        ]);
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

            // gt:0 porque un factor en cero no mueve el precio: lo que se quiere
            // en ese caso es desactivarlo, y para eso está la casilla. El tope
            // depende del modo: un recupero divide por (1 − %), así que con 100
            // o más no hay precio posible.
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
            'nombre.required'    => 'El factor necesita un nombre.',
            'nombre.unique'      => 'Ya hay un factor con ese nombre.',
            'modo.required'      => 'Elegí si es un recargo sobre el costo o un recupero sobre la venta.',
            'porcentaje.numeric' => 'Escribí el porcentaje como 3 o 2,375.',
            'porcentaje.decimal' => 'El porcentaje puede tener hasta tres decimales.',
            'porcentaje.gt'      => 'Un factor en cero no mueve el precio. Si no querés aplicarlo, desactivalo.',
            'porcentaje.max'     => 'Un recupero del 100 % o más no tiene precio que lo cubra.',
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