<?php

namespace App\Http\Requests\Api;

use App\Models\PoliticaPrecios;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los cuatro valores de la política del comercio (tramo F). Sólo edición: la fila existe desde la
 * migración. Los cuatro viajan siempre: es la política entera, no un parche campo por campo.
 *
 * Métodos:
 *   prepareForValidation()  los números en formato argentino
 *   rules()                 reglas
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
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
            // gte:1: 0,25 escrito donde iba 2,5 divide por cuatro el catálogo entero, y sale un número, no un error.
            'ganancia_global'   => ['required', 'numeric', 'decimal:0,3', 'gte:1', 'max:999.999'],
            'recargo_lista'     => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999.999'],
            'redondeo_multiplo' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100000'],
            'redondeo_modo'     => ['required', Rule::in(array_keys(PoliticaPrecios::MODOS_REDONDEO))],
        ];
    }

    public function messages(): array
    {
        return [
            'ganancia_global.required'   => 'Escribí la ganancia global.',
            'ganancia_global.numeric'    => 'Escribí el multiplicador como 2,5.',
            'ganancia_global.decimal'    => 'El multiplicador puede tener hasta tres decimales.',
            'ganancia_global.gte'        => 'Un multiplicador menor que 1 vende por debajo del costo. Para liquidar un producto puntual, usá el precio manual.',
            'ganancia_global.max'        => 'El multiplicador puede llegar hasta :max.',
            'recargo_lista.required'     => 'Escribí el recargo del precio de lista (0 si no tiene).',
            'recargo_lista.numeric'      => 'Escribí el recargo como 15 o 12,5.',
            'recargo_lista.decimal'      => 'El recargo puede tener hasta tres decimales.',
            'recargo_lista.min'          => 'El recargo no puede ser negativo: el precio de lista nunca es menor que el de contado.',
            'recargo_lista.max'          => 'El recargo puede llegar hasta :max %.',
            'redondeo_multiplo.required' => 'Escribí a qué múltiplo redondear.',
            'redondeo_multiplo.numeric'  => 'Escribí el múltiplo como 100 o 0,01.',
            'redondeo_multiplo.decimal'  => 'El múltiplo puede tener hasta dos decimales.',
            'redondeo_multiplo.gt'       => 'Escribí a qué múltiplo redondear. 0,01 redondea al centavo, que es no redondear.',
            'redondeo_multiplo.max'      => 'El múltiplo puede llegar hasta :max.',
            'redondeo_modo.required'     => 'Elegí cómo redondear.',
            'redondeo_modo.in'           => 'Elegí cómo redondear.',
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
