<?php

namespace App\Http\Requests;

use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;


/**
 * Validación del formulario de orden de compra. Alta y edición del borrador.
 *
 * `estado` y `total_estimado` se declaran **prohibited**. El estado lo mueve la
 * máquina de estados y el total se deriva de las líneas; omitirlos los ignoraría en
 * silencio, y el intento de aprobar una orden mandando `estado=aprobada` en el
 * cuerpo desaparecería sin dejar rastro.
 *   loProvee()   el proveedor de la orden provee ese producto
 */
class OrdenCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige compra.crear o compra.editar
    }

    protected function prepareForValidation(): void
    {
        $lineas = $this->input('lineas');

        if (! is_array($lineas)) {
            return;
        }

        // Una fila completamente vacía es una fila que el usuario agregó y no usó:
        // se descarta, igual que un campo de texto vacío se trata como ausente. Si
        // tiene **algo** cargado se conserva, para que la validación le diga qué le
        // falta en lugar de borrarle lo que escribió.
        $lineas = array_values(array_filter(
            $lineas,
            fn ($linea) => is_array($linea) && collect($linea)->contains(fn ($valor) => filled($valor)),
        ));

        // El costo se escribe "25.000,50" y la regla `numeric` espera "25000.50".
        // Importe::normalizar() sólo acepta las formas con una única lectura posible
        // y devuelve lo ambiguo sin tocar, para que la regla lo rechace en vez de
        // adivinar: leer "1.500" como 1,5 guardaría un costo de mil quinientos
        // pesos como uno con cincuenta sin reportar ningún error.
        foreach ($lineas as $indice => $linea) {
            if (array_key_exists('costo_unitario', $linea)) {
                $lineas[$indice]['costo_unitario'] = Importe::normalizar($linea['costo_unitario']);
            }
        }

        $this->merge(['lineas' => $lineas]);
    }

    public function rules(): array
    {
        return [
            'proveedor_id' => [
                'required', 'integer',
                Rule::exists('proveedores', 'id')->where('activo', true),
            ],

            'observaciones' => ['nullable', 'string', 'max:255'],

            'lineas'   => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*' => ['array'],

            'lineas.*.producto_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('productos', 'id'),
                $this->loProvee(...),
            ],

            'lineas.*.cantidad_pedida' => ['required', 'integer', 'min:1', 'max:100000'],

            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0', 'max:9999999999'],

            'estado'         => ['prohibited'],
            'total_estimado' => ['prohibited'],
        ];
    }

    /** @var array<int, int>|null */
    private ?array $aceptables = null;

    /**
     * Los productos que esta orden puede tener.
     *
     * Los que el proveedor provee —no se le compra a alguien algo que no vende—,
     * **más** los que la orden ya tenía aunque el vínculo se haya quitado después.
     * Esa excepción es el mismo criterio que `ProductoRequest::referenciaActiva()`:
     * una referencia que cambió después no puede volver inválido un registro que ya
     * existe. Sin ella, desvincular un proveedor dejaría sin poder editar las
     * órdenes viejas que lo mencionan.
     *
     * Se calcula una vez por petición y no una por línea: una orden de cincuenta
     * productos haría cien consultas de validación.
     *
     * @return array<int, int>  producto_id => posición, para buscar con isset
     */
    private function aceptables(): array
    {
        if ($this->aceptables !== null) {
            return $this->aceptables;
        }

        $provee = DB::table('producto_proveedor')
            ->where('proveedor_id', $this->integer('proveedor_id'))
            ->pluck('producto_id')
            ->map(fn ($id) => (int) $id);

        $orden = $this->route('orden');

        $mismoProveedor = $orden !== null
            && (int) $orden->proveedor_id === $this->integer('proveedor_id');

        $yaEstaban = $mismoProveedor
            ? $orden->lineas()->pluck('producto_id')->map(fn ($id) => (int) $id)
            : collect();

        return $this->aceptables = $provee->merge($yaEstaban)->flip()->all();
    }

    /** @var array<int, int>|null */
    private ?array $provistos = null;

    /**
     * Los productos que el proveedor de la orden provee.
     *
     * No hay excepción para «el producto ya estaba en la orden», y eso es un
     * cambio respecto de la primera versión. La excepción existía con el criterio de
     * `ProductoRequest::referenciaActiva()` —una referencia que cambió después no
     * puede volver inválido un registro existente—, pero acá **ese estado no se
     * puede alcanzar**: este Form Request sirve sólo a la edición, sólo se edita un
     * borrador, un borrador es una orden abierta, y
     * `ProductoProveedorService::desvincular()` rechaza mientras haya un pedido en
     * curso de ese par. Era lógica muerta, y la encontró el test que intentaba
     * reproducir el caso.
     *
     * Consecuencia buscada: si alguien cambia el proveedor de un borrador y deja las
     * líneas, se rechazan contra el proveedor nuevo. Es lo correcto —una orden no
     * puede pedirle a alguien algo que no vende— y la pantalla filtra el selector
     * para que no se llegue ahí por accidente.
     *
     * Se calcula una vez por petición y no una por línea: una orden de cincuenta
     * productos haría cincuenta consultas de validación.
     *
     * @return array<int, int>  producto_id => posición, para buscar con isset
     */
    private function provistos(): array
    {
        if ($this->provistos !== null) {
            return $this->provistos;
        }

        return $this->provistos = DB::table('producto_proveedor')
            ->where('proveedor_id', $this->integer('proveedor_id'))
            ->pluck('producto_id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();
    }

    /**
     * La pantalla ya filtra el selector a los productos del proveedor. Esto es la
     * barrera, para una petición armada a mano o para un selector que quedó abierto
     * mientras alguien desvinculaba el producto en otra pestaña.
     */
    protected function loProvee(string $atributo, mixed $valor, Closure $fallar): void
    {
        if (! ctype_digit((string) $valor)) {
            return;   // la regla `integer` ya lo rechaza; no se encadenan dos errores
        }

        if (! array_key_exists((int) $valor, $this->provistos())) {
            $fallar('Ese proveedor no provee este producto. Cargalo en los proveedores del producto, o elegí otro.');
        }
    }

    public function messages(): array
    {
        return [
            'proveedor_id.required' => 'Elegí a qué proveedor se le hace el pedido.',
            'proveedor_id.exists'   => 'Ese proveedor no existe o está dado de baja: no se le pueden hacer pedidos nuevos.',

            'lineas.required' => 'La orden necesita al menos un producto.',
            'lineas.min'      => 'La orden necesita al menos un producto.',
            'lineas.max'      => 'Una orden no puede tener más de 50 productos. Dividila en dos pedidos.',

            'lineas.*.producto_id.required' => 'Elegí el producto de esta línea.',
            'lineas.*.producto_id.distinct' => 'Ese producto está cargado más de una vez. Dejá una sola línea con la cantidad total.',
            'lineas.*.producto_id.exists'   => 'Ese producto no existe.',

            'lineas.*.cantidad_pedida.required' => 'Escribí cuántas unidades se piden.',
            'lineas.*.cantidad_pedida.min'      => 'La cantidad pedida tiene que ser al menos 1.',

            'lineas.*.costo_unitario.required' => 'Escribí el costo unitario acordado.',
            'lineas.*.costo_unitario.numeric'  => 'El costo no se entiende. Escribilo como 25.000,50.',
            'lineas.*.costo_unitario.min'      => 'El costo no puede ser negativo.',

            'estado.prohibited'         => 'El estado de la orden lo decide el sistema.',
            'total_estimado.prohibited' => 'El total se calcula a partir de las líneas.',
        ];
    }

    public function attributes(): array
    {
        return [
            'proveedor_id'  => 'proveedor',
            'observaciones' => 'observaciones',
        ];
    }
}