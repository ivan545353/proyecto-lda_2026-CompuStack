<?php

namespace App\Http\Requests;

use App\Models\PoliticaPrecios;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de los tres números del comercio. Sólo edición: la fila existe
 * desde la migración y no se crea ni se borra.
 *
 * Métodos:
 *   prepareForValidation()  los tres números en formato argentino
 *   rules()                 reglas
 *   messages() / attributes()
 */
class PoliticaPreciosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ganancia_global'   => Importe::normalizar($this->input('ganancia_global')),
            'recargo_lista'     => Importe::normalizar($this->input('recargo_lista')),
            'redondeo_multiplo' => Importe::normalizar($this->input('redondeo_multiplo')),
        ]);
    }

    public function rules(): array
    {
        return [
            // gte:1 es la red contra el error más caro de esta pantalla: 0,25
            // escrito donde iba 2,5 divide por cuatro el precio del catálogo
            // entero en un clic, y sale un número, no un error.
            'ganancia_global'   => ['required', 'numeric', 'decimal:0,3', 'gte:1', 'max:999.999'],
            'recargo_lista'     => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999.999'],
            'redondeo_multiplo' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100000'],
            'redondeo_modo'     => ['required', Rule::in(array_keys(PoliticaPrecios::MODOS_REDONDEO))],
        ];
    }

    public function messages(): array
    {
        return [
            'ganancia_global.numeric' => 'Escribí el multiplicador como 2,5.',
            'ganancia_global.gte'     => 'Un multiplicador menor que 1 vende por debajo del costo. Para liquidar un producto puntual, usá el precio manual.',
            'recargo_lista.min'       => 'El recargo no puede ser negativo: el precio de lista nunca es menor que el de contado.',
            'redondeo_multiplo.gt'    => 'Escribí a qué múltiplo redondear. 0,01 redondea al centavo, que es no redondear.',
        ];
    }

    public function attributes(): array
    {
        return [
            'ganancia_global'   => 'ganancia global',
            'recargo_lista'     => 'recargo del precio de lista',
            'redondeo_multiplo' => 'múltiplo de redondeo',
            'redondeo_modo'     => 'modo de redondeo',
        ];
    }
}