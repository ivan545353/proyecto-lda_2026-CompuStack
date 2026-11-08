<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Support\Importe;
use App\Models\Impuesto;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validación del formulario de producto. Sirve para el alta y la edición.
 *
 * Métodos:
 *   authorize()             true; el permiso lo exige la ruta
 *   prepareForValidation()  precios en formato argentino, alícuota y código
 *   rules()                 reglas
 *   referenciaActiva()      exists, y activo sólo si el valor cambia
 *   limiteDeImagenes()      entre las que quedan y las nuevas, no más de 5
 *   messages() / attributes()
 *   limiteSinOrden()        sin orden: actuales + nuevas, no más de 5
 *   elementoDeOrden()       cada elemento del orden existe
 *   ordenDeImagenes()       el orden validado, como estructura para el servicio
 */
class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige producto.crear o producto.editar
    }

    protected function prepareForValidation(): void
    {
        $codigo   = $this->input('codigo');
        $alicuota = $this->input('alicuota_iva');

        $this->merge([
            // La base compara sin distinguir mayúsculas: guardarlo en un solo
            // formato evita listados con "g505" y "G505" mezclados.
            'codigo' => is_string($codigo) ? mb_strtoupper($codigo) : $codigo,

            // "1.500,50" → "1500.50". Lo que no se pueda interpretar sin
            // ambigüedad llega sin tocar y lo rechaza la regla decimal.
            'precio_contado' => Importe::normalizar($this->input('precio_contado')),
            'ganancia'       => Importe::normalizar($this->input('ganancia')),

            // Al formato con el que la base devuelve la alícuota: 21, '21' y
            // '21.0' pasan a '21.00', que es como está en el catálogo.
            'alicuota_iva' => is_numeric($alicuota) ? number_format((float) $alicuota, 2, '.', '') : $alicuota,

            'stock_minimo'        => $this->input('stock_minimo') ?? 0,
            'cantidad_reposicion' => $this->input('cantidad_reposicion') ?? 0,
            'activo'              => $this->boolean('activo'),
            'precio_manual'       => $this->boolean('precio_manual'),
            // El orden llega como JSON en un solo campo (ver ordenDeImagenes).
            // Si no se puede leer, queda en false y lo rechaza la regla array:
            // nunca se interpreta un JSON roto como "sin orden".
            ...($this->has('imagenes_orden') ? ['orden' => $this->decodificarOrden()] : []),
        ]);
    }

    public function rules(): array
    {
        $producto = $this->route('producto');

        return [
            'codigo' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique('productos', 'codigo')->ignore($producto?->id),
            ],
            'nombre'      => ['required', 'string', 'min:3', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:5000'],

            'categoria_id' => ['required', 'integer', $this->referenciaActiva('categorias', 'categoria_id')],
            'marca_id'     => ['nullable', 'integer', $this->referenciaActiva('marcas', 'marca_id')],

            'precio_lista' => ['prohibited'],

            'precio_contado' => [
                Rule::when(
                    $this->boolean('precio_manual'),
                    ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
                    ['prohibited'],
                ),
            ],

            'precio_manual' => ['required', 'boolean', Rule::when($producto === null, ['accepted'])],

            'ganancia' => ['nullable', 'numeric', 'decimal:0,3', 'gte:1', 'max:999.999'],

            'alicuota_iva' => ['required', Rule::in($this->alicuotasAdmitidas())],

            'stock_minimo'        => ['required', 'integer', 'min:0', 'max:100000'],
            'cantidad_reposicion' => [
                'required', 'integer', 'min:0', 'max:100000',

                Rule::when($this->integer('stock_minimo') > 0, ['gt:0']),
            ],

            'activo' => ['required', 'boolean'],

            'imagenes'   => ['nullable', 'array', 'max:'.Producto::MAX_IMAGENES, $this->limiteSinOrden(...)],
            'imagenes.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],

            // La lista final de imágenes. Ver ordenDeImagenes().
            'orden'   => ['nullable', 'array', 'max:'.Producto::MAX_IMAGENES],
            'orden.*' => ['bail', 'string', 'distinct', $this->elementoDeOrden(...)],
        ];
    }

    private function referenciaActiva(string $tabla, string $campo): Exists
    {
        $actual = $this->route('producto')?->{$campo};
        $regla  = Rule::exists($tabla, 'id');

        return (string) $actual === (string) $this->input($campo)
            ? $regla
            : $regla->where('activo', true);
    }

    /**
     * Las alícuotas del catálogo, más la que el producto ya tiene aunque se
     * haya dado de baja.
     *
     * @return array<int, string>
     */
    private function alicuotasAdmitidas(): array
    {
        $activas = array_keys(Impuesto::opcionesDeAlicuota());
        $actual  = $this->route('producto')?->alicuota_iva;

        return $actual === null
            ? $activas
            : array_values(array_unique([...$activas, $actual]));
    }


    private function limiteSinOrden(string $atributo, mixed $nuevas, Closure $fallar): void
    {
        if ($this->has('imagenes_orden')) {
            return;
        }

        $total = count($this->route('producto')?->imagenes ?? []) + count((array) $nuevas);

        if ($total > Producto::MAX_IMAGENES) {
            $fallar(sprintf(
                'Un producto puede tener hasta %d imágenes. Con estas quedaría con %d.',
                Producto::MAX_IMAGENES,
                $total,
            ));
        }
    }

    /**
     * Cada elemento del orden tiene que referirse a algo que existe: una
     * imagen que el producto TIENE, o un archivo que viene en esta petición.
     */
    private function elementoDeOrden(string $atributo, mixed $valor, Closure $fallar): void
    {
        [$tipo, $referencia] = array_pad(explode(':', $valor, 2), 2, '');

        if ($tipo === 'actual') {
            if (! in_array($referencia, $this->route('producto')?->imagenes ?? [], true)) {
                $fallar('Se pidió conservar una imagen que el producto no tiene. Recargá la página e intentá de nuevo.');
            }

            return;
        }

        if ($tipo === 'nueva') {
            $cantidad = count($this->file('imagenes', []));

            if (! ctype_digit($referencia) || (int) $referencia >= $cantidad) {
                $fallar('Falta una de las imágenes nuevas. Volvé a seleccionarla.');
            }

            return;
        }

        $fallar('No se pudo leer el orden de las imágenes. Recargá la página e intentá de nuevo.');
    }

    private function decodificarOrden(): array|false
    {
        $orden = json_decode((string) $this->input('imagenes_orden'), true);

        return is_array($orden) && array_is_list($orden) ? $orden : false;
    }

    /**
     * El orden final de las imágenes, ya validado, en un formato que no
     * depende de cómo lo mandó el formulario. null si no se mandó.
     *
     * @return array<int, array{tipo: 'actual', ruta: string}|array{tipo: 'nueva', indice: int}>|null
     */
    public function ordenDeImagenes(): ?array
    {
        if (! $this->has('imagenes_orden')) {
            return null;
        }

        return array_map(function (string $elemento): array {
            [$tipo, $referencia] = explode(':', $elemento, 2);

            return $tipo === 'actual'
                ? ['tipo' => 'actual', 'ruta' => $referencia]
                : ['tipo' => 'nueva', 'indice' => (int) $referencia];
        }, $this->validated('orden', []));
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'El producto necesita un código.',
            'codigo.regex'    => 'El código sólo puede tener letras, números, puntos y guiones, sin espacios.',
            'codigo.unique'   => 'Ya existe un producto con ese código.',

            'nombre.required' => 'El producto necesita un nombre.',
            'nombre.min'      => 'El nombre debe tener al menos 3 caracteres.',

            'categoria_id.required' => 'Elegí una categoría.',
            'categoria_id.exists'   => 'La categoría elegida no existe o está inactiva.',
            'marca_id.exists'       => 'La marca elegida no existe o está inactiva.',

            'precio_lista.prohibited'   => 'El precio de lista ya no se carga a mano: sale del precio de contado más el recargo que fija la pantalla de Precios.',
            'precio_contado.required'   => 'Con el precio fijado a mano, escribí el precio de contado.',
            'precio_contado.prohibited' => 'Este producto calcula su precio solo. Si querés fijarlo a mano, marcá la casilla.',
            'precio_contado.numeric'    => 'Escribí el precio como 1500, 1500,50 o 1.500,50.',
            'precio_contado.decimal'    => 'El precio puede tener hasta dos decimales.',
            'precio_contado.gt'         => 'El precio tiene que ser mayor que cero.',
            'precio_contado.max'        => 'El precio es demasiado alto.',
            'precio_manual.accepted'    => 'Un producto nuevo todavía no tiene costo, así que su precio se carga a mano. Después podés pasarlo al cálculo automático.',
            'ganancia.numeric'          => 'Escribí el multiplicador como 2,5.',
            'ganancia.gte'              => 'Un multiplicador menor que 1 vende por debajo del costo. Para eso está el precio manual.',

            'alicuota_iva.in' => 'Elegí una de las alícuotas de IVA de la lista.',

            'cantidad_reposicion.gt' => 'Si el producto tiene stock mínimo, indicá cuántas unidades pedir al reponer.',

            'imagenes.*.image' => 'El archivo :position no es una imagen.',
            'imagenes.*.mimes' => 'La imagen :position tiene que ser JPG, PNG o WEBP.',
            'imagenes.*.max'   => 'La imagen :position supera 1 MB.',
            'imagenes.max'     => 'Se pueden subir hasta :max imágenes a la vez.',
            'orden.array'      => 'No se pudo leer el orden de las imágenes. Recargá la página e intentá de nuevo.',
            'orden.max'        => 'Un producto puede tener hasta :max imágenes.',
            'orden.*.distinct' => 'Una imagen aparece dos veces. Recargá la página e intentá de nuevo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'codigo'              => 'código',
            'nombre'              => 'nombre',
            'descripcion'         => 'descripción',
            'categoria_id'        => 'categoría',
            'marca_id'            => 'marca',
            'precio_lista'        => 'precio de lista',
            'precio_manual'       => 'precio fijado a mano',
            'ganancia'            => 'ganancia',
            'precio_contado'      => 'precio de contado',
            'alicuota_iva'        => 'alícuota de IVA',
            'stock_minimo'        => 'stock mínimo',
            'cantidad_reposicion' => 'cantidad a reponer',
            'activo'              => 'estado',
            'imagenes'            => 'imágenes',
            'orden' => 'orden de las imágenes',
        ];
    }
}