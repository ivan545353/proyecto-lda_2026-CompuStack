<?php

namespace App\Http\Requests\Api;

use App\Models\Impuesto;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El simulador de precios (tramo F): un costo suelto y su precio, sin guardar nada. En la web el campo
 * oculto `simular` distinguía «abrir la pantalla» de «simular»; en la API pedir la simulación ya es
 * simular, así que el costo y la alícuota son obligatorios. La ganancia ausente es la global.
 *
 * Métodos:
 *   prepareForValidation()  costo y ganancia en formato argentino
 *   rules()                 reglas
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class SimulacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.ver
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'costo'    => Importe::normalizar($this->input('costo')),
            'ganancia' => Importe::normalizar($this->input('ganancia')),
        ]);
    }

    public function rules(): array
    {
        return [
            'costo'        => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'ganancia'     => ['nullable', 'numeric', 'decimal:0,3', 'gte:1', 'max:999.999'],
            'alicuota_iva' => ['required', Rule::in(array_keys(Impuesto::opcionesDeAlicuota()))],
        ];
    }

    public function messages(): array
    {
        return [
            'costo.required'        => 'Escribí un costo para simular.',
            'costo.numeric'         => 'Escribí el costo como 20000 o 20.000,50.',
            'costo.decimal'         => 'El costo puede tener hasta dos decimales.',
            'costo.gt'              => 'El costo tiene que ser mayor que cero.',
            'costo.max'             => 'El costo es demasiado grande.',
            'ganancia.numeric'      => 'Escribí el multiplicador como 2,5.',
            'ganancia.decimal'      => 'El multiplicador puede tener hasta tres decimales.',
            'ganancia.gte'          => 'Un multiplicador menor que 1 vende por debajo del costo.',
            'ganancia.max'          => 'El multiplicador puede llegar hasta :max.',
            'alicuota_iva.required' => 'Elegí la alícuota de IVA.',
            'alicuota_iva.in'       => 'Elegí una de las alícuotas de la lista.',
        ];
    }

    public function attributes(): array
    {
        return [
            'costo'        => 'costo neto',
            'ganancia'     => 'ganancia',
            'alicuota_iva' => 'alícuota de IVA',
        ];
    }
}
