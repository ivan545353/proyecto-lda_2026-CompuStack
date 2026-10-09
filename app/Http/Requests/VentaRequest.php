<?php

namespace App\Http\Requests;

use App\Services\VentaService;
use App\Support\Importe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de venta. Alta y edición del presupuesto.
 *
 * Uno solo para las dos, y `$this->route('venta')` las distingue, que es el patrón
 * del proyecto.
 *
 * **Lo que el formulario NO manda está declarado `prohibited`, no omitido.** El
 * estado lo mueve la máquina de estados, los totales se derivan de las líneas, el
 * canal y el modo de entrega están fijos en la Etapa 1, el vendedor sale de la
 * sesión, y el precio de cada línea lo lee el servidor. Todos esos campos ya tienen
 * dos barreras silenciosas —el servicio no los mira y el modelo no los puede
 * escribir— pero omitirlos acá los haría desaparecer **sin dejar rastro**: un
 * intento de cargar `lineas[0][precio_unitario]=1` se descartaría y el usuario vería
 * un presupuesto correcto sin saber que su dato se tiró. `prohibited` lo rechaza y
 * lo explica, que es la doctrina de M-31 aplicada a la entrada que nadie debería
 * mandar.
 *
 * **El tope de descuento se valida acá y en el servicio.** Acá para que el error
 * caiga en el campo y `old()` conserve lo que el usuario escribió; allá para que la
 * API de la Etapa 3 herede la regla. Los dos leen el mismo `config/venta.php` a
 * través de `VentaService::topeDeDescuento()`, así que el número está en un solo
 * lugar (A-12).
 *
 * Métodos:
 *   prepareForValidation()  descarta filas vacías y normaliza el porcentaje
 *   rules()                 las reglas
 *   vendible()              el producto se puede vender, o ya estaba en la venta
 *   messages() / attributes()
 */
class VentaRequest extends FormRequest
{
    /** @var array<int, int>|null */
    private ?array $aceptables = null;

    public function authorize(): bool
    {
        return true;   // la ruta exige venta.crear o venta.editar
    }

    protected function prepareForValidation(): void
    {
        // "7,5" → "7.5". `numeric` espera el punto, y en Argentina se escribe con
        // coma. Lo ambiguo llega sin tocar y la regla lo rechaza: nunca se adivina.
        if ($this->has('descuento_porcentaje')) {
            $this->merge([
                'descuento_porcentaje' => Importe::normalizar($this->input('descuento_porcentaje')),
            ]);
        }

        $lineas = $this->input('lineas');

        if (! is_array($lineas)) {
            return;
        }

        // Una fila completamente vacía es una fila que el usuario agregó y no usó: se
        // descarta. Si tiene **algo** cargado se conserva, para que la validación le
        // diga qué le falta en lugar de borrarle lo que escribió.
        $this->merge([
            'lineas' => array_values(array_filter(
                $lineas,
                fn ($linea) => is_array($linea) && collect($linea)->contains(fn ($valor) => filled($valor)),
            )),
        ]);
    }

    public function rules(): array
    {
        $tope = $this->tope();

        return [
            // Null es consumidor final, y es el caso más común del mostrador.
            // `clientes` no tiene columna `activo`: un cliente no se desactiva, así
            // que no hay estado que exigir acá.
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')],

            'descuento_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:'.$tope],

            'observaciones' => ['nullable', 'string', 'max:255'],

            'lineas'   => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*' => ['array'],

            'lineas.*.producto_id' => [
                'required', 'integer',
                // `distinct` es lo que impide el mismo producto dos veces, y el
                // error cae en la línea culpable. El servicio lo vuelve a verificar,
                // porque la base no tiene UNIQUE que lo garantice.
                'distinct',
                Rule::exists('productos', 'id'),
                $this->vendible(...),
            ],

            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:100000'],

            // --- Lo que el servidor decide y nadie manda ---------------------
            'estado'       => ['prohibited'],
            'canal'        => ['prohibited'],
            'modo_entrega' => ['prohibited'],
            'usuario_id'   => ['prohibited'],
            'subtotal'     => ['prohibited'],
            'descuento'    => ['prohibited'],
            'costo_envio'  => ['prohibited'],
            'total'        => ['prohibited'],

            'lineas.*.precio_unitario'   => ['prohibited'],
            'lineas.*.alicuota_iva'      => ['prohibited'],
            'lineas.*.costo_unitario'    => ['prohibited'],
            'lineas.*.neto'              => ['prohibited'],
            'lineas.*.iva'               => ['prohibited'],
            'lineas.*.total'             => ['prohibited'],
            'lineas.*.cantidad_devuelta' => ['prohibited'],
        ];
    }

    /**
     * El tope que rige para quien está cargando, en porcentaje.
     *
     * Sale del servicio y no de `config()` directo, para que el Request, la vista y
     * el servicio lean el mismo número por el mismo camino: el tope depende del
     * permiso, y duplicar esa decisión acá sería duplicar A-12.
     */
    private function tope(): float
    {
        return app(VentaService::class)->topeDeDescuento($this->user());
    }

    /**
     * El producto se puede vender.
     *
     * Activo, **o** ya presente en la venta que se está editando. La excepción es la
     * misma que `ProductoRequest::referenciaActiva()`: una referencia que cambió
     * después no puede volver inválido un registro que ya existe. Sin ella, dar de
     * baja un producto dejaría sin poder editar los presupuestos que lo mencionan, y
     * el usuario no tendría ni cómo quitar ese renglón.
     *
     * No es laxitud: la venta con un producto de baja tampoco se va a poder cobrar
     * —eso lo valida el pase a `pagada`, en la segunda mitad de la fase— y
     * `recotizar()` sí lo rechaza, porque recotizar es cotizar y no se cotiza lo que
     * no se puede vender.
     */
    protected function vendible(string $atributo, mixed $valor, Closure $fallar): void
    {
        if (! ctype_digit((string) $valor)) {
            return;   // la regla `integer` ya lo rechaza; no se encadenan dos errores
        }

        if (! array_key_exists((int) $valor, $this->aceptables())) {
            $fallar('Ese producto está dado de baja y no se puede vender. Elegí otro, o volvé a activarlo en el catálogo.');
        }
    }

    /**
     * Los productos que esta venta puede tener.
     *
     * Se consultan **sólo los ids de la petición**, no el catálogo entero: una
     * consulta por petición, no una por línea ni una tabla completa en memoria.
     *
     * @return array<int, int>  producto_id => posición, para buscar con array_key_exists
     */
    private function aceptables(): array
    {
        if ($this->aceptables !== null) {
            return $this->aceptables;
        }

        $pedidos = collect((array) $this->input('lineas', []))
            ->pluck('producto_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique();

        $activos = $pedidos->isEmpty()
            ? collect()
            : DB::table('productos')
                ->whereIn('id', $pedidos)
                ->where('activo', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

        $yaEstaban = $this->route('venta')?->lineas()->pluck('producto_id')->map(fn ($id) => (int) $id)
            ?? collect();

        return $this->aceptables = $activos->merge($yaEstaban)->flip()->all();
    }

    public function messages(): array
    {
        $tope = $this->tope();

        return [
            'cliente_id.exists' => 'Ese cliente no existe. Si es nuevo, cargalo primero en Clientes.',

            'descuento_porcentaje.numeric' => 'El descuento no se entiende. Escribilo como 10 o 7,5.',
            'descuento_porcentaje.min'     => 'El descuento no puede ser negativo: eso sería un recargo, y el recargo por tarjeta no lo calcula este sistema.',
            'descuento_porcentaje.max'     => "El descuento máximo que podés aplicar es del {$tope} %. Un descuento mayor lo tiene que cargar alguien con autorización para hacerlo.",

            'lineas.required' => 'La venta necesita al menos un producto.',
            'lineas.min'      => 'La venta necesita al menos un producto.',
            'lineas.max'      => 'Una venta no puede tener más de 50 productos.',

            'lineas.*.producto_id.required' => 'Elegí el producto de esta línea.',
            'lineas.*.producto_id.distinct' => 'Ese producto ya está cargado en otra línea. Dejá una sola, con la cantidad total.',
            'lineas.*.producto_id.exists'   => 'Ese producto no existe.',

            'lineas.*.cantidad.required' => 'Escribí cuántas unidades se venden.',
            'lineas.*.cantidad.min'      => 'La cantidad tiene que ser al menos 1. Si no se vende, quitá el renglón.',
            'lineas.*.cantidad.integer'  => 'La cantidad se escribe en unidades enteras.',

            'estado.prohibited'       => 'El estado de la venta lo decide el sistema, y se mueve con las acciones de la ficha.',
            'canal.prohibited'        => 'El canal lo decide el sistema: en la Etapa 1 todas las ventas son de mostrador.',
            'modo_entrega.prohibited' => 'La entrega es siempre retiro en el local: los envíos son de una etapa siguiente.',
            'usuario_id.prohibited'   => 'El vendedor es quien está usando el sistema, y no se elige.',
            'subtotal.prohibited'     => 'El subtotal se calcula a partir de las líneas.',
            'descuento.prohibited'    => 'El descuento se calcula a partir del porcentaje.',
            'costo_envio.prohibited'  => 'El costo de envío es de una etapa siguiente.',
            'total.prohibited'        => 'El total se calcula a partir de las líneas y el descuento.',

            'lineas.*.precio_unitario.prohibited'   => 'El precio lo lee el sistema del catálogo: nunca viene del formulario.',
            'lineas.*.alicuota_iva.prohibited'      => 'La alícuota de IVA sale del producto.',
            'lineas.*.costo_unitario.prohibited'    => 'El costo sale del producto.',
            'lineas.*.neto.prohibited'              => 'El neto se calcula a partir del precio y la alícuota.',
            'lineas.*.iva.prohibited'               => 'El IVA se calcula a partir del precio y la alícuota.',
            'lineas.*.total.prohibited'             => 'El total de la línea se calcula a partir del precio y la cantidad.',
            'lineas.*.cantidad_devuelta.prohibited' => 'Lo devuelto se registra con una devolución, no editando la venta.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cliente_id'           => 'cliente',
            'descuento_porcentaje' => 'descuento',
            'observaciones'        => 'observaciones',
            'lineas'               => 'productos de la venta',
        ];
    }
}