<?php

namespace App\Http\Requests;

use App\Models\Impuesto;
use App\Support\Importe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de un impuesto. Sirve para el alta y la edición.
 *
 * El `alcance` sólo se elige al crear: ver la regla.
 *
 * Métodos:
 *   prepareForValidation()  alícuota en formato argentino
 *   rules()                 reglas
 *   alicuotaSinRepetir()    dos alícuotas de IVA con el mismo número se pisan
 *   messages() / attributes()
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

        $this->merge([
            // "10,5" → "10.50". Lo que no se pueda interpretar llega sin tocar
            // y lo rechaza la regla numeric.
            'alicuota' => is_numeric($alicuota) ? number_format((float) $alicuota, 2, '.', '') : $alicuota,
            'orden'    => $this->input('orden') ?? 0,
            'activo'   => $this->boolean('activo'),
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

            // min:0 y no gt:0 porque el exento es un impuesto del 0 % y tiene
            // que existir: el comprobante declara su IVA contenido igual.
            'alicuota' => [
                'required', 'numeric', 'decimal:0,2', 'min:0', 'max:99.99',
                $this->alicuotaSinRepetir(...),
            ],

            // Al editar, el único valor admitido es el que ya tiene. Si una
            // alícuota de IVA pasara a alcance general, el motor se la sumaría
            // a todos los productos ADEMÁS del IVA propio de cada uno, y los
            // precios saldrían con IVA dos veces sin que nada lo avise.
            'alcance' => [
                'required',
                Rule::in($impuesto === null ? array_keys(Impuesto::ALCANCES) : [$impuesto->alcance]),
            ],

            'comprobante' => ['required', Rule::in(array_keys(Impuesto::COMPROBANTES))],
            'orden'       => ['required', 'integer', 'min:0', 'max:9999'],
            'activo'      => ['required', 'boolean'],
        ];
    }

    /**
     * Dos alícuotas de IVA con el mismo porcentaje serían dos filas que dicen
     * lo mismo, y el producto las referencia por valor: el desglose no sabría
     * cuál de las dos nombrar. No se puede declarar en la base porque sólo
     * aplica a las filas de alcance 'producto'.
     */
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
            'nombre.unique'        => 'Ya hay un impuesto con ese nombre.',
            'alicuota.required'    => 'Escribí la alícuota.',
            'alicuota.numeric'     => 'Escribí la alícuota como 21 o 10,5.',
            'alicuota.decimal'     => 'La alícuota puede tener hasta dos decimales.',
            'alicuota.max'         => 'La alícuota no puede llegar al 100 %.',
            'alcance.in'           => 'El alcance de un impuesto no se cambia: creá uno nuevo y dale de baja a este.',
            'comprobante.required' => 'Elegí cómo se declara en el comprobante.',
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