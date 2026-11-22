<?php

namespace App\Http\Requests\Api;

use App\Models\Impuesto;
use App\Support\Importe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de un impuesto (tramo F). Rechaza, no corrige (M-31): sólo normaliza el
 * formato de la alícuota; `orden` y `activo` viajan siempre. El `alcance` sólo se elige al crear.
 *
 * Métodos:
 *   prepareForValidation()  alícuota en formato argentino
 *   rules()                 reglas
 *   alicuotaSinRepetir()    dos alícuotas de IVA con el mismo número se pisan
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class ImpuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige precio.editar
    }

    protected function prepareForValidation(): void
    {
        $alicuota = Importe::normalizar($this->input('alicuota'));

        // "10,5" → "10.50". Lo que no se pueda interpretar llega sin tocar y lo rechaza `numeric`.
        $this->merge([
            'alicuota' => is_numeric($alicuota) ? number_format((float) $alicuota, 2, '.', '') : $alicuota,
        ]);
    }

    public function rules(): array
    {
        $impuesto = $this->route('impuesto');

        return [
            'nombre' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('impuestos', 'nombre')->ignore($impuesto?->id),
            ],

            // min:0: el exento es un impuesto del 0 % y el comprobante declara su IVA contenido igual.
            'alicuota' => [
                'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99.99',
                $this->alicuotaSinRepetir(...),
            ],

            // Al editar, el único valor admitido es el que ya tiene: una alícuota de IVA que pasara a
            // general se sumaría a todo el catálogo además del IVA propio de cada producto.
            'alcance' => [
                'required',
                Rule::in($impuesto === null ? array_keys(Impuesto::ALCANCES) : [$impuesto->alcance]),
            ],

            'comprobante' => ['required', Rule::in(array_keys(Impuesto::COMPROBANTES))],
            'orden'       => ['required', 'integer', 'min:0', 'max:9999'],
            'activo'      => ['required', 'boolean'],
        ];
    }

    /** El producto referencia la alícuota por valor: dos filas con el mismo porcentaje no sabrían cuál nombrar. */
    private function alicuotaSinRepetir(string $atributo, mixed $valor, Closure $fallar): void
    {
        if ($this->input('alcance') !== 'producto') {
            return;
        }

        $actual = $this->route('impuesto')?->id;

        $repetida = Impuesto::query()
            ->where('alcance', 'producto')
            ->where('alicuota', $valor)
            ->when($actual, fn ($query) => $query->whereKeyNot($actual))
            ->exists();

        if ($repetida) {
            $fallar('Ya hay una alícuota de IVA con ese porcentaje. Editá la que existe en lugar de duplicarla.');
        }
    }

    public function messages(): array
    {
        return [
            'nombre.required'      => 'El impuesto necesita un nombre.',
            'nombre.min'           => 'El nombre tiene que tener al menos :min letras.',
            'nombre.max'           => 'El nombre puede tener hasta :max letras.',
            'nombre.unique'        => 'Ya hay un impuesto con ese nombre.',
            'alicuota.required'    => 'Escribí la alícuota.',
            'alicuota.numeric'     => 'Escribí la alícuota como 21 o 10,5.',
            'alicuota.decimal'     => 'La alícuota puede tener hasta dos decimales.',
            'alicuota.min'         => 'La alícuota no puede ser negativa.',
            'alicuota.max'         => 'La alícuota no puede llegar al 100 %.',
            'alcance.required'     => 'Elegí el alcance.',
            'alcance.in'           => 'El alcance de un impuesto no se cambia: creá uno nuevo y dale de baja a este.',
            'comprobante.required' => 'Elegí cómo se declara en el comprobante.',
            'comprobante.in'       => 'Elegí cómo se declara en el comprobante.',
            'orden.required'       => 'Indicá el orden.',
            'orden.integer'        => 'El orden es un número entero.',
            'orden.min'            => 'El orden no puede ser negativo.',
            'orden.max'            => 'El orden puede llegar hasta :max.',
            'activo.required'      => 'Indicá si el impuesto está activo.',
            'activo.boolean'       => 'Indicá si el impuesto está activo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre'      => 'nombre',
            'alicuota'    => 'alícuota',
            'alcance'     => 'alcance',
            'comprobante' => 'declaración en el comprobante',
            'orden'       => 'orden',
            'activo'      => 'estado',
        ];
    }
}
