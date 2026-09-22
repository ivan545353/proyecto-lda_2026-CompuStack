<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Support\Importe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validación del formulario de producto. Sirve para el alta y la edición.
 *
 * Reemplaza a ItemDto, cuyos setters corregían en vez de rechazar :
 * usaban precio = 9999999 como valor por defecto si faltaba el campo, y
 * truncaban la descripción a 255 caracteres aunque la columna era TEXT.
 *
 * No incluye stock ni costo_promedio: los mueve sólo el StockService (Fase 5),
 * dejando un movimiento en el kardex . Tampoco peso_gramos ni destacado,
 * que sirven a la tienda y a los envíos de la Etapa 2.
 *
 * Métodos:
 *   authorize()             true; el permiso lo exige la ruta
 *   prepareForValidation()  precios en formato argentino, alícuota y código
 *   rules()                 reglas
 *   referenciaActiva()      exists, y activo sólo si el valor cambia
 *   limiteDeImagenes()      entre las que quedan y las nuevas, no más de 5
 *   messages() / attributes()
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
            'precio_lista'   => Importe::normalizar($this->input('precio_lista')),
            'precio_contado' => Importe::normalizar($this->input('precio_contado')),

            // Al formato de las claves de ALICUOTAS_IVA: 21, '21' y '21.0'
            // pasan a '21.00'. Ver la constante.
            'alicuota_iva' => is_numeric($alicuota) ? number_format((float) $alicuota, 2, '.', '') : $alicuota,

            'stock_minimo'        => $this->input('stock_minimo') ?? 0,
            'cantidad_reposicion' => $this->input('cantidad_reposicion') ?? 0,
            'activo'              => $this->boolean('activo'),
            
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
            'proveedor_id' => ['nullable', 'integer', $this->referenciaActiva('proveedores', 'proveedor_id')],

            // decimal:0,2 RECHAZA un tercer decimal en vez de dejar que la
            // base lo redondee en silencio. 9.999.999.999,99 es el máximo que
            // entra en decimal(12,2).
            'precio_lista'   => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'precio_contado' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'lte:precio_lista'],
            'alicuota_iva'   => ['required', Rule::in(array_keys(Producto::ALICUOTAS_IVA))],

            'stock_minimo'        => ['required', 'integer', 'min:0', 'max:100000'],
            'cantidad_reposicion' => [
                'required', 'integer', 'min:0', 'max:100000',
                // Un producto con mínimo pero sin cantidad a pedir nunca se
                // repondría: la reposición automática (Fase 5) saltea los que
                // tienen cantidad 0. Mejor avisarlo ahora que descubrirlo
                // cuando el producto está agotado.
                Rule::when($this->integer('stock_minimo') > 0, ['gt:0']),
            ],

            'activo' => ['required', 'boolean'],

            'imagenes'   => ['nullable', 'array', $this->limiteDeImagenes(...)],
            'imagenes.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],

            // Sólo se pueden quitar imágenes que el producto TIENE. Sin esta
            // regla, alguien podría mandar quitar_imagenes[]=../../.env y el
            // servicio intentaría borrar ese archivo del disco: es un
            // recorrido de directorios (path traversal) a través de un
            // formulario de catálogo.
            'quitar_imagenes'   => ['nullable', 'array'],
            'quitar_imagenes.*' => ['string', Rule::in($producto?->imagenes ?? [])],
        ];
    }

    /**
     * La referencia tiene que existir, y además estar activa si CAMBIA.
     *
     * Mismo criterio que el padre de una categoría: un producto cuya marca se
     * desactivó después tiene que poder seguir editándose sin que el
     * formulario lo rechace por algo que el usuario no tocó.
     */
    private function referenciaActiva(string $tabla, string $campo): Exists
    {
        $actual = $this->route('producto')?->{$campo};
        $regla  = Rule::exists($tabla, 'id');

        return (string) $actual === (string) $this->input($campo)
            ? $regla
            : $regla->where('activo', true);
    }

    /** Las que ya tiene, menos las que se quitan, más las nuevas: no más de MAX_IMAGENES. */
    private function limiteDeImagenes(string $atributo, mixed $nuevas, Closure $fallar): void
    {
        $actuales = $this->route('producto')?->imagenes ?? [];
        $quitadas = array_intersect((array) $this->input('quitar_imagenes', []), $actuales);
        $total    = count($actuales) - count($quitadas) + count((array) $nuevas);

        if ($total > Producto::MAX_IMAGENES) {
            $fallar(sprintf(
                'Un producto puede tener hasta %d imágenes. Con estas quedaría con %d: quitá alguna de las actuales o subí menos.',
                Producto::MAX_IMAGENES,
                $total,
            ));
        }
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
            'proveedor_id.exists'   => 'El proveedor elegido no existe o está inactivo.',

            'precio_lista.required'  => 'El producto necesita un precio de lista.',
            'precio_lista.numeric'   => 'Escribí el precio como 1500, 1500,50 o 1.500,50.',
            'precio_lista.decimal'   => 'El precio puede tener hasta dos decimales.',
            'precio_lista.gt'        => 'El precio tiene que ser mayor que cero.',
            'precio_lista.max'       => 'El precio es demasiado alto.',
            'precio_contado.numeric' => 'Escribí el precio como 1500, 1500,50 o 1.500,50.',
            'precio_contado.decimal' => 'El precio puede tener hasta dos decimales.',
            'precio_contado.gt'      => 'El precio tiene que ser mayor que cero.',
            'precio_contado.lte'     => 'El precio de contado no puede ser mayor que el de lista.',

            'alicuota_iva.in' => 'Elegí una de las alícuotas de IVA de la lista.',

            'cantidad_reposicion.gt' => 'Si el producto tiene stock mínimo, indicá cuántas unidades pedir al reponer.',

            'imagenes.*.image' => 'El archivo :position no es una imagen.',
            'imagenes.*.mimes' => 'La imagen :position tiene que ser JPG, PNG o WEBP.',
            'imagenes.*.max'   => 'La imagen :position supera 1 MB.',
            'quitar_imagenes.*.in' => 'Se pidió quitar una imagen que el producto no tiene.',
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
            'proveedor_id'        => 'proveedor',
            'precio_lista'        => 'precio de lista',
            'precio_contado'      => 'precio de contado',
            'alicuota_iva'        => 'alícuota de IVA',
            'stock_minimo'        => 'stock mínimo',
            'cantidad_reposicion' => 'cantidad a reponer',
            'activo'              => 'estado',
            'imagenes'            => 'imágenes',
        ];
    }
}