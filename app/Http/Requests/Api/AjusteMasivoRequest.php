<?php

namespace App\Http\Requests\Api;

use App\Models\LoteAjuste;
use App\Support\Importe;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * El ajuste masivo de precios (tramo F): la operación, su parámetro y el recorte.
 *
 * **Uno solo para la vista previa (`GET`) y para el ajuste (`POST`)**, como en la web: con reglas
 * distintas, la pantalla podría previsualizar un conjunto y el ajuste escribir otro, y la vista previa
 * dejaría de ser una salvaguarda. En la web, el campo oculto `previsualizar` distinguía «abrir la
 * pantalla» de «previsualizar»; en la API pedir la vista previa ya es previsualizar. La casilla
 * `incluir_manuales` viaja siempre (la API rechaza, no corrige).
 *
 * Contrato: operacion (costo | precio | ganancia | recalculo | redondeo), porcentaje (con signo),
 * ganancia, redondeo_multiplo, alcance (todos | recorte), categoria_id, marca_id, proveedor_id, ids[],
 * costo_desde/hasta, precio_desde/hasta, incluir_manuales; y pagina / por_pagina en la vista previa.
 *
 * Métodos:
 *   prepareForValidation()  los números en formato argentino, con el signo del porcentaje
 *   rules()                 reglas
 *   withValidator()         las tres coherencias que una regla sola no puede ver
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class AjusteMasivoRequest extends PaginadoRequest
{
    /** Los recortes, para preguntar si vino alguno. */
    private const RECORTES = [
        'categoria_id', 'marca_id', 'proveedor_id', 'ids',
        'costo_desde', 'costo_hasta', 'precio_desde', 'precio_hasta',
    ];

    /** Los campos que el servicio lee: el paginado no es parte del ajuste ni del criterio del lote. */
    public const CAMPOS = [
        'operacion', 'porcentaje', 'ganancia', 'redondeo_multiplo', 'alcance', 'incluir_manuales',
        ...self::RECORTES,
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

        foreach (['ganancia', 'redondeo_multiplo', 'costo_desde', 'costo_hasta', 'precio_desde', 'precio_hasta'] as $campo) {
            if ($this->has($campo)) {
                $this->merge([$campo => Importe::normalizar($this->input($campo))]);
            }
        }
    }

    // `Importe::normalizar()` no conoce el signo: se saca, se normaliza el número y se vuelve a pegar.
    private function conSigno(mixed $valor): mixed
    {
        if (! is_string($valor) || ! str_starts_with(trim($valor), '-')) {
            return Importe::normalizar($valor);
        }

        return '-'.Importe::normalizar(ltrim(trim($valor), '-'));
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),

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

            'incluir_manuales' => ['required', 'boolean'],
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

    /** El alcance y el recorte tienen que decir lo mismo: «todos» con un recorte haría creer que se filtró. */
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

    /** El extremo de arriba no puede ser menor que el de abajo: así el recorte no alcanza a nadie. */
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

    /** Lo que el servicio recibe: los campos del ajuste, sin el paginado. */
    public function ajuste(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::CAMPOS));
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'operacion.required'            => 'Elegí qué operación aplicar.',
            'operacion.in'                  => 'Elegí una de las operaciones de la lista.',
            'porcentaje.required_if'        => 'Escribí el porcentaje del ajuste.',
            'porcentaje.numeric'            => 'Escribí el porcentaje como 15 o como -7,5.',
            'porcentaje.decimal'            => 'El porcentaje puede tener hasta tres decimales.',
            'porcentaje.min'                => 'Una baja del 100 % o más dejaría el precio en cero. Para liquidar un producto puntual, usá el precio manual.',
            'porcentaje.max'                => 'El porcentaje puede llegar hasta :max.',
            'ganancia.required_if'          => 'Escribí el multiplicador de ganancia del conjunto.',
            'ganancia.numeric'              => 'Escribí el multiplicador como 2,5.',
            'ganancia.decimal'              => 'El multiplicador puede tener hasta tres decimales.',
            'ganancia.gte'                  => 'Un multiplicador menor que 1 vende por debajo del costo, y acá lo haría sobre todo el conjunto.',
            'ganancia.max'                  => 'El multiplicador puede llegar hasta :max.',
            'redondeo_multiplo.required_if' => 'Escribí a qué múltiplo redondear.',
            'redondeo_multiplo.numeric'     => 'Escribí el múltiplo como 100 o 0,01.',
            'redondeo_multiplo.gt'          => 'Escribí a qué múltiplo redondear. 0,01 redondea al centavo, que es no redondear.',
            'redondeo_multiplo.max'         => 'El múltiplo puede llegar hasta :max.',
            'alcance.required'              => 'Elegí si el ajuste es para todos los productos o para un recorte.',
            'alcance.in'                    => 'Elegí si el ajuste es para todos los productos o para un recorte.',
            'categoria_id.exists'           => 'Esa categoría no existe.',
            'marca_id.exists'               => 'Esa marca no existe.',
            'proveedor_id.exists'           => 'Ese proveedor no existe.',
            'ids.array'                     => 'Los productos elegidos tienen que venir como lista.',
            'ids.max'                       => 'Son demasiados productos elegidos a mano. Para un conjunto así conviene recortar por categoría, marca o proveedor.',
            'ids.*.integer'                 => 'Uno de los productos elegidos no es válido.',
            'ids.*.exists'                  => 'Uno de los productos elegidos no existe.',
            'costo_desde.numeric'           => 'Escribí el costo como 20000 o 20.000,50.',
            'costo_hasta.numeric'           => 'Escribí el costo como 20000 o 20.000,50.',
            'precio_desde.numeric'          => 'Escribí el precio como 20000 o 20.000,50.',
            'precio_hasta.numeric'          => 'Escribí el precio como 20000 o 20.000,50.',
            'incluir_manuales.required'     => 'Indicá si el ajuste incluye los productos con precio manual.',
            'incluir_manuales.boolean'      => 'Indicá si el ajuste incluye los productos con precio manual.',
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
