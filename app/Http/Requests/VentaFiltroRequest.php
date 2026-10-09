<?php

namespace App\Http\Requests;

use App\Models\Venta;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de ventas.
 *
 * Declara el contrato: estos filtros y no otros, con el mismo nombre que el scope
 * del modelo. Es la contracara de A-24, donde el bug existió porque no había ningún
 * lugar donde constara qué filtros acepta el listado: `SaleController` mandaba unos
 * y el DAO leía otros, y nadie se enteró en once commits.
 *
 * `estado` acepta los once valores de `Venta::ESTADOS` y no sólo los cuatro que la
 * Etapa 1 produce: el **selector** de la pantalla ofrece
 * `Venta::ESTADOS_EN_USO`, pero un enlace guardado puede traer cualquiera y la
 * respuesta correcta es un listado vacío, no un rebote.
 *
 * `cliente_id` no tiene selector en el formulario, y es a propósito. Llega desde un
 * enlace —el historial de compras de un cliente, que es el pendiente #8— y la
 * búsqueda por nombre del cliente ya está cubierta por `?q=`. Un desplegable con
 * todos los clientes cargaría cientos de opciones en cada carga de la pantalla para
 * resolver algo que el buscador resuelve mejor.
 */
class VentaFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige venta.ver
    }

    public function rules(): array
    {
        return [
            'q'           => ['nullable', 'string', 'max:100'],
            'estado'      => ['nullable', Rule::in(array_keys(Venta::ESTADOS))],
            'cliente_id'  => ['nullable', 'integer', 'exists:clientes,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:users,id'],
            'desde'       => ['nullable', 'date'],
            'hasta'       => ['nullable', 'date', 'after_or_equal:desde'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Un filtro inválido redirige al listado limpio con un aviso, en vez de rebotar
     * con una pantalla de errores sobre un formulario que el usuario no completó.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('ventas.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todas las ventas.'
            )
        );
    }
}