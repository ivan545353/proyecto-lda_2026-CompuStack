<?php

namespace App\Http\Requests;

use App\Support\Importe;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validación del armador de pedido: una pantalla, varias órdenes.
 *
 * Tres reglas propias del armador:
 *
 *   1. **El proveedor de cada línea tiene que proveer ese producto**, contra
 *      `producto_proveedor`. La pantalla filtra el selector; esto es la barrera.
 *
 *   2. **El par (producto, proveedor) no se repite**, y el producto solo **sí**
 *      puede. El mismo producto a dos proveedores es partir una compra, y es
 *      legítimo: son dos órdenes distintas, y el `UNIQUE (orden_compra_id,
 *      producto_id)` de la base lo permite. El mismo par dos veces serían dos
 *      líneas de la misma orden para el mismo producto, y eso no.
 *
 *   3. **El producto tiene que estar activo.** Acá se puede exigir, al contrario
 *      que en `OrdenCompraRequest`: un pedido nuevo no tiene líneas viejas que
 *      proteger, así que no hace falta la excepción de «el que ya estaba».
 *
 * No hay campo de observaciones. Cada grupo se convierte en un documento distinto,
 * y una sola observación para todos diría lo mismo a tres proveedores, que casi
 * siempre es falso. Se agregan editando cada borrador, que es para lo que el
 * borrador existe.
 *
 * Métodos:
 *   authorize()             true; la ruta exige compra.crear
 *   prepareForValidation()  descarta filas vacías y normaliza el costo
 *   rules()                 las reglas
 *   withValidator()         el par repetido, señalando la línea
 *   provistoPorEseProveedor()  la barrera del par contra la pivote
 *   messages() / attributes()
 */
class PedidoCompraRequest extends FormRequest
{
    /** @var array<int, array<int, bool>>|null */
    private ?array $pares = null;

    public function authorize(): bool
    {
        return true;   // la ruta exige compra.crear
    }

    protected function prepareForValidation(): void
    {
        $lineas = $this->input('lineas');

        if (! is_array($lineas)) {
            return;
        }

        // Una fila completamente vacía es una fila que el usuario agregó y no usó:
        // se descarta. Si tiene algo cargado se conserva, para que la validación le
        // diga qué le falta en lugar de borrarle lo que escribió.
        $lineas = array_values(array_filter(
            $lineas,
            fn ($linea) => is_array($linea) && collect($linea)->contains(fn ($valor) => filled($valor)),
        ));

        // "25.000,50" → "25000.50". Lo ambiguo llega sin tocar y lo rechaza
        // `numeric`: leer "1.500" como 1,5 guardaría mil quinientos pesos como uno
        // con cincuenta sin reportar ningún error.
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
            'lineas'   => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*' => ['array'],

            // Activo, sí: un pedido nuevo no arrastra líneas viejas que proteger.
            'lineas.*.producto_id' => [
                'required', 'integer',
                Rule::exists('productos', 'id')->where('activo', true),
            ],

            'lineas.*.proveedor_id' => [
                'required', 'integer',
                Rule::exists('proveedores', 'id')->where('activo', true),
                $this->provistoPorEseProveedor(...),
            ],

            'lineas.*.cantidad_pedida' => ['required', 'integer', 'min:1', 'max:100000'],

            // Cero es válido, igual que en OrdenCompraRequest: un producto que nunca
            // se compró a ese proveedor no tiene costo conocido, y el borrador en
            // cero se nota antes de aprobar.
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $vistos = [];

            foreach ((array) $this->input('lineas', []) as $indice => $linea) {
                $producto  = $linea['producto_id'] ?? null;
                $proveedor = $linea['proveedor_id'] ?? null;

                if ($producto === null || $proveedor === null) {
                    continue;   // les falta un campo; otras reglas lo dicen
                }

                $par = "{$producto}-{$proveedor}";

                // El error se cuelga de la línea culpable y no del arreglo entero,
                // así la pantalla puede marcar esa fila.
                if (isset($vistos[$par])) {
                    $validator->errors()->add(
                        "lineas.{$indice}.producto_id",
                        'Este producto ya está cargado para ese mismo proveedor. Dejá una sola línea, con la cantidad total.',
                    );
                }

                $vistos[$par] = true;
            }
        });
    }

    /**
     * El proveedor de la línea provee ese producto.
     *
     * El atributo llega como `lineas.3.proveedor_id`, así que el índice sale de ahí
     * y con él se lee el producto de la misma línea. Es la única forma de validar
     * dos campos hermanos en un arreglo.
     */
    protected function provistoPorEseProveedor(string $atributo, mixed $valor, Closure $fallar): void
    {
        $indice   = explode('.', $atributo)[1] ?? null;
        $producto = $this->input("lineas.{$indice}.producto_id");

        if (! ctype_digit((string) $valor) || ! ctype_digit((string) $producto)) {
            return;   // otras reglas ya lo rechazan; no se encadenan dos errores
        }

        if (! isset($this->pares()[(int) $producto][(int) $valor])) {
            $fallar('Ese proveedor no provee este producto. Elegí otro, o cargalo en los proveedores del producto.');
        }
    }

    /**
     * Los pares (producto, proveedor) que existen, para los productos de esta
     * petición.
     *
     * Una consulta por petición y no una por línea: un pedido de cincuenta líneas
     * haría cincuenta consultas de validación.
     *
     * @return array<int, array<int, bool>>  producto_id => [proveedor_id => true]
     */
    private function pares(): array
    {
        if ($this->pares !== null) {
            return $this->pares;
        }

        $productos = collect((array) $this->input('lineas', []))
            ->pluck('producto_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $this->pares = [];

        if ($productos === []) {
            return $this->pares;
        }

        $filas = DB::table('producto_proveedor')
            ->whereIn('producto_id', $productos)
            ->get(['producto_id', 'proveedor_id']);

        foreach ($filas as $fila) {
            $this->pares[(int) $fila->producto_id][(int) $fila->proveedor_id] = true;
        }

        return $this->pares;
    }

    public function messages(): array
    {
        return [
            'lineas.required' => 'El pedido necesita al menos un producto.',
            'lineas.min'      => 'El pedido necesita al menos un producto.',
            'lineas.max'      => 'Un pedido no puede tener más de 50 líneas. Guardalo y armá otro.',

            'lineas.*.producto_id.required' => 'Elegí el producto de esta línea.',
            'lineas.*.producto_id.exists'   => 'Ese producto no existe o está dado de baja.',

            'lineas.*.proveedor_id.required' => 'Elegí a qué proveedor se le pide este producto.',
            'lineas.*.proveedor_id.exists'   => 'Ese proveedor no existe o está dado de baja: no se le pueden hacer pedidos nuevos.',

            'lineas.*.cantidad_pedida.required' => 'Escribí cuántas unidades se piden.',
            'lineas.*.cantidad_pedida.min'      => 'La cantidad pedida tiene que ser al menos 1.',

            'lineas.*.costo_unitario.required' => 'Escribí el costo unitario acordado.',
            'lineas.*.costo_unitario.numeric'  => 'El costo no se entiende. Escribilo como 25.000,50.',
            'lineas.*.costo_unitario.min'      => 'El costo no puede ser negativo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'lineas' => 'productos del pedido',
        ];
    }
}