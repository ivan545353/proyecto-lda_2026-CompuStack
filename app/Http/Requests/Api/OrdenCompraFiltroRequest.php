<?php

namespace App\Http\Requests\Api;

use App\Models\OrdenCompra;
use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de órdenes de compra (tramo G). Los mismos que la web, con el nombre de su
 * scope; un filtro inválido —también un rango invertido— es 422 en lugar de volver al listado limpio
 * (M-31, HL-120).
 *
 * Métodos:
 *   criterios()  lo que se aplicó, normalizado
 */
class OrdenCompraFiltroRequest extends PaginadoRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'            => ['nullable', 'string', 'max:100'],
            'estado'       => ['nullable', Rule::in(array_keys(OrdenCompra::ESTADOS_DEL_FILTRO))],
            'origen'       => ['nullable', Rule::in(array_keys(OrdenCompra::ORIGENES))],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'desde'        => ['nullable', 'date'],
            'hasta'        => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'                => 'La búsqueda puede tener hasta :max caracteres.',
            'estado.in'            => 'Ese estado de orden no existe.',
            'origen.in'            => 'Ese origen no existe: reposición o cargada por una persona.',
            'proveedor_id.integer' => 'El proveedor no es válido.',
            'proveedor_id.exists'  => 'Ese proveedor no existe.',
            'desde.date'           => 'La fecha «desde» no es una fecha.',
            'hasta.date'           => 'La fecha «hasta» no es una fecha.',
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde»: no devolvería nada.',
        ];
    }

    public function criterios(): array
    {
        return [
            'q'            => $this->filled('q') ? (string) $this->input('q') : null,
            'estado'       => $this->filled('estado') ? (string) $this->input('estado') : null,
            'origen'       => $this->filled('origen') ? (string) $this->input('origen') : null,
            'proveedor_id' => $this->filled('proveedor_id') ? $this->integer('proveedor_id') : null,
            'desde'        => $this->filled('desde') ? (string) $this->input('desde') : null,
            'hasta'        => $this->filled('hasta') ? (string) $this->input('hasta') : null,
        ];
    }
}
