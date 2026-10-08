<?php

namespace App\Http\Requests;

use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de orden de compra. Alta y edición del borrador.
 *
 * El proveedor se exige **activo siempre**, no sólo si cambia: una orden es un
 * compromiso de plata, y no corresponde comprometerlo con un proveedor que se dio
 * de baja. Es distinto del caso de `ProductoRequest`, donde una referencia que se
 * desactivó después tiene que poder seguir editándose porque el producto ya existe.
 *
 * `estado` y `total_estimado` se declaran **prohibited**. El estado lo mueve la
 * máquina de estados y el total se deriva de las líneas; omitirlos los ignoraría en
 * silencio, y el intento de aprobar una orden mandando `estado=aprobada` en el
 * cuerpo desaparecería sin dejar rastro.
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

            // `distinct` es la primera barrera del UNIQUE (orden, producto).
            'lineas.*.producto_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('productos', 'id'),
            ],

            'lineas.*.cantidad_pedida' => ['required', 'integer', 'min:1', 'max:100000'],

            // Cero es válido: un producto que nunca se compró no tiene costo
            // conocido, y la orden automática lo deja en cero a propósito para que
            // se note antes de aprobar.
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0', 'max:9999999999'],

            'estado'         => ['prohibited'],
            'total_estimado' => ['prohibited'],
        ];
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