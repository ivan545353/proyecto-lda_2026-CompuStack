<?php

namespace App\Http\Requests;

use App\Support\Importe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del vínculo entre un producto y un proveedor. Alta y edición.
 *
 * El PRODUCTO lo determina la ruta, nunca el cuerpo: `producto_id` está
 * prohibido, igual que `cliente_id` en DireccionRequest. Si se leyera del cuerpo,
 * alguien podría mover un vínculo a otro producto mandando un id distinto. El
 * servicio tampoco lo escribiría —recibe el producto como argumento—, pero
 * declararlo prohibido hace que el intento se vea en lugar de ignorarse: mismo
 * criterio con el que se cerró C-3.
 *
 * En la EDICIÓN el proveedor también lo determina la ruta, así que `proveedor_id`
 * pasa a `prohibited`. Cambiarle el proveedor a un vínculo no es editarlo: es
 * quitar uno y agregar otro, y cada una de esas dos cosas tiene su propia acción,
 * su propio mensaje y sus propias reglas —quitar puede estar bloqueado por un
 * pedido en curso, agregar exige que el proveedor esté activo—.
 *
 * `costo_ultimo` y `codigo_proveedor` son **`present` y no sólo `nullable`**. Es
 * la diferencia entre «lo quiero vacío» y «no mandé el campo»: sin `present`, una
 * petición que omite el costo blanquearía el que estaba guardado, que es M-31 con
 * otra ropa. Con `present`, omitirlo es un rechazo.
 *
 * Métodos:
 *   authorize()             true; las rutas exigen producto.editar
 *   prepareForValidation()  normaliza el importe, recorta el código, resuelve el checkbox
 *   rules()                 las reglas; el proveedor sólo llega en el alta
 *   messages() / attributes()
 */
class ProductoProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // las rutas exigen producto.editar
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            // El checkbox sin marcar no viaja en el POST. Sin esto no habría forma
            // de distinguir "no lo quiero preferido" de "no mandé el campo".
            'es_preferido' => $this->boolean('es_preferido'),
        ];

        if ($this->has('costo_ultimo')) {
            // "25.000,50" → "25000.50". Lo ambiguo llega sin tocar y lo rechaza la
            // regla `numeric`: leer "1.500" como 1,5 guardaría un costo de mil
            // quinientos pesos como uno con cincuenta sin reportar ningún error.
            $merge['costo_ultimo'] = Importe::normalizar($this->input('costo_ultimo'));
        }

        if ($this->has('codigo_proveedor')) {
            $codigo = $this->input('codigo_proveedor');
            // Se recorta y nada más. A diferencia de `productos.codigo`, que se
            // pasa a mayúsculas porque es NUESTRO código y tiene un UNIQUE donde
            // "g505" y "G505" serían dos filas distintas, éste es el código del
            // PROVEEDOR y no tiene índice: cambiarle la caja sería editar el dato
            // de un tercero, no normalizar un formato.
            $merge['codigo_proveedor'] = is_string($codigo) ? trim($codigo) : $codigo;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $producto  = $this->route('producto');
        $esEdicion = $this->route('proveedor') !== null;

        return [
            // En el alta se exige ACTIVO: vincular un proveedor es preparar una
            // compra, y no corresponde preparar una con alguien dado de baja. Es
            // el criterio de OrdenCompraRequest, y el contrario al de
            // ProductoRequest, donde una referencia que se desactivó después tiene
            // que poder seguir editándose. Acá esa excepción no hace falta: en la
            // edición el proveedor no viene del cuerpo, así que un vínculo con un
            // proveedor inactivo se sigue pudiendo editar igual.
            'proveedor_id' => $esEdicion
                ? ['prohibited']
                : [
                    'required', 'integer',
                    Rule::exists('proveedores', 'id')->where('activo', true),

                    // Primera barrera de la PK compuesta, la que produce un mensaje
                    // en español. La segunda está en ProductoProveedorService, que
                    // rechaza dentro del bloqueo: entre que esta regla consulta y
                    // el insert ocurre, otra pestaña puede insertar el mismo par.
                    Rule::unique('producto_proveedor', 'proveedor_id')
                        ->where('producto_id', $producto->id),
                ],

            // `present` a propósito: ver el docblock de la clase.
            //
            // `gt:0` y no `min:0`: cero no significa «no sé cuánto cobra», para eso
            // está vacío. Un costo de cero diría que lo regala, y la comparación de
            // precios lo pondría primero. 9.999.999.999,99 es el máximo que entra
            // en decimal(12,2), y `decimal:0,2` rechaza un tercer decimal en vez de
            // dejar que la base lo redondee en silencio.
            'costo_ultimo' => ['present', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],

            'codigo_proveedor' => ['present', 'nullable', 'string', 'max:40'],

            'es_preferido' => ['required', 'boolean'],

            // El producto lo determina la ruta, no el cuerpo de la petición.
            'producto_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'proveedor_id.required'   => 'Elegí el proveedor.',
            'proveedor_id.exists'     => 'Ese proveedor no existe o está dado de baja: no se le pueden cargar productos nuevos.',
            'proveedor_id.unique'     => 'Ese proveedor ya está cargado para este producto. Editá el vínculo que ya existe.',
            'proveedor_id.prohibited' => 'El proveedor de este vínculo no se cambia. Quitalo y cargá el otro.',

            'costo_ultimo.present' => 'Falta el costo. Dejalo vacío si todavía no lo sabés.',
            'costo_ultimo.numeric' => 'El costo no se entiende. Escribilo como 25.000,50.',
            'costo_ultimo.decimal' => 'El costo lleva como máximo dos decimales.',
            'costo_ultimo.gt'      => 'El costo tiene que ser mayor que cero. Si todavía no lo sabés, dejalo vacío.',

            'codigo_proveedor.present' => 'Falta el código del proveedor. Dejalo vacío si no lo usás.',
            'codigo_proveedor.max'     => 'El código del proveedor no puede tener más de 40 caracteres.',

            'producto_id.prohibited' => 'El vínculo pertenece al producto de esta pantalla.',
        ];
    }

    public function attributes(): array
    {
        return [
            'proveedor_id'     => 'proveedor',
            'costo_ultimo'     => 'costo',
            'codigo_proveedor' => 'código del proveedor',
            'es_preferido'     => 'proveedor preferido',
        ];
    }
}