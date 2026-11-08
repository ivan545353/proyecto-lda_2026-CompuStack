<?php

namespace App\Http\Requests;

use App\Models\LoteAjuste;
use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validación del ajuste masivo de precios: la operación, su parámetro y el recorte.
 *
 * **Uno solo para la previsualización y para el ajuste**, aunque una viaje por GET y
 * el otro por POST. Si tuvieran reglas distintas, la pantalla podría previsualizar un
 * conjunto y el ajuste escribir otro, que es el peor defecto que esta pantalla puede
 * tener: la previsualización obligatoria dejaría de ser una salvaguarda y pasaría a
 * ser una decoración.
 *
 * Contrato:
 *   operacion          costo | precio | ganancia | recalculo | redondeo
 *   porcentaje         con costo y con precio; admite negativo, que es una baja
 *   ganancia           con ganancia; el multiplicador del conjunto
 *   redondeo_multiplo  con redondeo
 *   alcance            todos | recorte
 *   categoria_id       la categoría y sus subcategorías
 *   marca_id           marca exacta
 *   proveedor_id       los que se le compran a ese proveedor
 *   ids[]              la selección con casillas del listado
 *   costo_desde/hasta  rango de costo, inclusive
 *   precio_desde/hasta rango de precio de contado, inclusive
 *   incluir_manuales   la casilla de la tercera salvaguarda
 *   page               la paginación de la tabla de previsualización
 *
 * Métodos:
 *   prepareForValidation()  los números en formato argentino
 *   rules()                 reglas
 *   withValidator()         las tres coherencias que una regla sola no puede ver
 *   messages() / attributes()
 */
class AjusteMasivoRequest extends FormRequest
{
    /** Los recortes, para preguntar si vino alguno. */
    private const RECORTES = [
        'categoria_id', 'marca_id', 'proveedor_id', 'ids',
        'costo_desde', 'costo_hasta', 'precio_desde', 'precio_hasta',
    ];

    public function authorize(): bool
    {
        return true;   // la ruta exige precio.ajustar_masivo
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('porcentaje')) {
            $this->merge(['porcentaje' => $this->conSigno($this->input('porcentaje'))]);
        }

        foreach (['ganancia', 'redondeo_multiplo',
            'costo_desde', 'costo_hasta', 'precio_desde', 'precio_hasta'] as $campo) {
            if ($this->has($campo)) {
                $this->merge([$campo => Importe::normalizar($this->input($campo))]);
            }
        }
    }

    private function conSigno(mixed $valor): mixed
    {
        if (! is_string($valor) || ! str_starts_with(trim($valor), '-')) {
            return Importe::normalizar($valor);
        }

        return '-'.Importe::normalizar(ltrim(trim($valor), '-'));
    }

    public function rules(): array
    {
        if ($this->isMethod('get') && ! $this->boolean('previsualizar')) {
            return [
                'ids'   => ['nullable', 'array', 'max:500'],
                'ids.*' => ['integer', Rule::exists('productos', 'id')],
            ];
        }
        return [
            'operacion' => ['required', Rule::in(LoteAjuste::ELEGIBLES)],
            'porcentaje' => [
                'required_if:operacion,costo,precio', 'nullable',
                'numeric', 'decimal:0,3', 'min:-99.999', 'max:999.999',
            ],

            'ganancia' => [
                'required_if:operacion,ganancia', 'nullable',
                'numeric', 'decimal:0,3', 'gte:1', 'max:999.999',
            ],

            'redondeo_multiplo' => [
                'required_if:operacion,redondeo', 'nullable',
                'numeric', 'decimal:0,2', 'gt:0', 'max:100000',
            ],

            'alcance' => ['required', Rule::in(['todos', 'recorte'])],

            'categoria_id' => ['nullable', 'integer', Rule::exists('categorias', 'id')],
            'marca_id'     => ['nullable', 'integer', Rule::exists('marcas', 'id')],
            'proveedor_id' => ['nullable', 'integer', Rule::exists('proveedores', 'id')],

            'ids'   => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer', Rule::exists('productos', 'id')],

            'costo_desde'  => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'costo_hasta'  => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'precio_desde' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'precio_hasta' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],

            'incluir_manuales' => ['nullable', 'boolean'],

            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->exigirUnAlcanceCoherente($validator);
            $this->rechazarUnPorcentajeQueNoHaceNada($validator);
            $this->exigirRangosEnOrden($validator);
        });
    }

    /**
     * El alcance y el recorte tienen que decir lo mismo.
     */
    private function exigirUnAlcanceCoherente(Validator $validator): void
    {
        $hayRecorte = collect(self::RECORTES)->contains(fn (string $campo) => filled($this->input($campo)));

        if ($this->input('alcance') === 'recorte' && ! $hayRecorte) {
            $validator->errors()->add('alcance',
                'Elegí al menos un recorte —una categoría, una marca, un proveedor, '
                .'productos sueltos o un rango—, o pedí explícitamente todos los productos.');
        }

        if ($this->input('alcance') === 'todos' && $hayRecorte) {
            $validator->errors()->add('alcance',
                'Pediste todos los productos y además un recorte. Elegí uno de los dos: '
                .'si el ajuste es para un rubro, el alcance es el recorte.');
        }
    }

    /** Un ajuste de 0 % corre y no cambia nada: lo que se quiso escribir es otro número. */
    private function rechazarUnPorcentajeQueNoHaceNada(Validator $validator): void
    {
        if (in_array($this->input('operacion'), ['costo', 'precio'], true)
            && is_numeric($this->input('porcentaje'))
            && (float) $this->input('porcentaje') === 0.0) {
            $validator->errors()->add('porcentaje',
                'Un ajuste de 0 % no cambia ningún precio. Escribí el porcentaje del aumento '
                .'o de la baja.');
        }
    }

    /**
     * El extremo de arriba no puede ser menor que el de abajo.

     */
    private function exigirRangosEnOrden(Validator $validator): void
    {
        foreach (['costo', 'precio'] as $campo) {
            $desde = $this->input("{$campo}_desde");
            $hasta = $this->input("{$campo}_hasta");

            if (is_numeric($desde) && is_numeric($hasta) && (float) $hasta < (float) $desde) {
                $validator->errors()->add("{$campo}_hasta",
                    'El límite de arriba no puede ser menor que el de abajo: así el recorte '
                    .'no alcanza a ningún producto.');
            }
        }
    }

    public function messages(): array
    {
        return [
            'porcentaje.required_if'        => 'Escribí el porcentaje del ajuste.',
            'porcentaje.numeric'            => 'Escribí el porcentaje como 15 o como -7,5.',
            'porcentaje.min'                => 'Una baja del 100 % o más dejaría el precio en cero. Para liquidar un producto puntual, usá el precio manual.',
            'ganancia.required_if'          => 'Escribí el multiplicador de ganancia del conjunto.',
            'ganancia.gte'                  => 'Un multiplicador menor que 1 vende por debajo del costo, y acá lo haría sobre todo el conjunto.',
            'redondeo_multiplo.required_if' => 'Escribí a qué múltiplo redondear.',
            'redondeo_multiplo.gt'          => 'Escribí a qué múltiplo redondear. 0,01 redondea al centavo, que es no redondear.',
            'ids.max'                       => 'Son demasiados productos elegidos a mano. Para un conjunto así conviene recortar por categoría, marca o proveedor.',
        ];
    }

    public function attributes(): array
    {
        return [
            'operacion'         => 'operación',
            'porcentaje'        => 'porcentaje',
            'ganancia'          => 'multiplicador de ganancia',
            'redondeo_multiplo' => 'múltiplo de redondeo',
            'alcance'           => 'alcance del ajuste',
            'categoria_id'      => 'categoría',
            'marca_id'          => 'marca',
            'proveedor_id'      => 'proveedor',
            'ids'               => 'productos elegidos',
            'costo_desde'       => 'costo desde',
            'costo_hasta'       => 'costo hasta',
            'precio_desde'      => 'precio desde',
            'precio_hasta'      => 'precio hasta',
            'incluir_manuales'  => 'incluir los de precio manual',
        ];
    }
}