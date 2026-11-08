<?php

namespace App\Http\Requests;

use App\Models\Impuesto;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del simulador de precios. Viaja por GET: el resultado queda en la
 * URL y se puede compartir.
 *
 * Métodos:
 *   prepareForValidation()  costo y ganancia en formato argentino
 *   rules()                 vacías si la pantalla todavía no simuló nada
 *   messages() / attributes()
 */
class SimuladorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.ver
    }

    protected function prepareForValidation(): void
    {
        if (! $this->boolean('simular')) {
            return;
        }

        $this->merge([
            'costo'    => Importe::normalizar($this->input('costo')),
            'ganancia' => Importe::normalizar($this->input('ganancia')),
        ]);
    }

    public function rules(): array
    {
        // Sin el campo oculto, la pantalla se está abriendo por primera vez: no
        // hay nada que validar y el simulador se ve vacío, no roto.
        if (! $this->boolean('simular')) {
            return [];
        }

        return [
            'costo' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],

            // Vacía significa «la global», y eso es lo que dice la ayuda del campo.
            'ganancia' => ['nullable', 'numeric', 'decimal:0,3', 'gte:1', 'max:999.999'],

            'alicuota_iva' => ['required', Rule::in(array_keys(Impuesto::opcionesDeAlicuota()))],
        ];
    }

    public function messages(): array
    {
        return [
            'costo.required'    => 'Escribí un costo para simular.',
            'costo.numeric'     => 'Escribí el costo como 20000 o 20.000,50.',
            'costo.decimal'     => 'El costo puede tener hasta dos decimales.',
            'costo.gt'          => 'El costo tiene que ser mayor que cero.',
            'ganancia.numeric'  => 'Escribí el multiplicador como 2,5.',
            'ganancia.gte'      => 'Un multiplicador menor que 1 vende por debajo del costo.',
            'alicuota_iva.in'   => 'Elegí una de las alícuotas de la lista.',
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