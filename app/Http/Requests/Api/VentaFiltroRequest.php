<?php

namespace App\Http\Requests\Api;

use App\Models\Cliente;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Validation\Rule;

/**
 * Los filtros del listado de ventas (tramo H). Los mismos que la web, con el nombre de su scope; un
 * filtro inválido —también un rango invertido— es 422 en lugar de volver al listado limpio (M-31, HL-120).
 * `estado` acepta los once de `Venta::ESTADOS`: un enlace guardado puede traer cualquiera.
 *
 * Métodos:
 *   criterios()  lo que se aplicó, normalizado; el cliente y el vendedor, también con su nombre (el chip)
 */
class VentaFiltroRequest extends PaginadoRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q'           => ['nullable', 'string', 'max:100'],
            'estado'      => ['nullable', Rule::in(array_keys(Venta::ESTADOS))],
            'cliente_id'  => ['nullable', 'integer', 'exists:clientes,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:users,id'],
            'canal'       => ['nullable', Rule::in(array_keys(Venta::CANALES))],
            'pago'        => ['nullable', Rule::in(array_keys(Venta::FILTROS_PAGO))],
            'desde'       => ['nullable', 'date'],
            'hasta'       => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'q.max'                => 'La búsqueda puede tener hasta :max caracteres.',
            'estado.in'            => 'Ese estado de venta no existe.',
            'cliente_id.integer'   => 'El cliente no es válido.',
            'cliente_id.exists'    => 'Ese cliente no existe.',
            'vendedor_id.integer'  => 'El vendedor no es válido.',
            'vendedor_id.exists'   => 'Ese vendedor no existe.',
            'canal.in'             => 'Ese canal no existe: mostrador o tienda online.',
            'pago.in'              => 'Ese filtro de pago no existe.',
            'desde.date'           => 'La fecha «desde» no es una fecha.',
            'hasta.date'           => 'La fecha «hasta» no es una fecha.',
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde»: no devolvería nada.',
        ];
    }

    public function criterios(): array
    {
        $cliente  = $this->filled('cliente_id') ? Cliente::find($this->integer('cliente_id')) : null;
        $vendedor = $this->filled('vendedor_id') ? User::find($this->integer('vendedor_id')) : null;

        return [
            'q'           => $this->filled('q') ? (string) $this->input('q') : null,
            'estado'      => $this->filled('estado') ? (string) $this->input('estado') : null,
            'cliente_id'  => $cliente?->id,
            'cliente'     => $cliente?->razon_social,
            'vendedor_id' => $vendedor?->id,
            'vendedor'    => $vendedor?->nombre_completo,
            'canal'       => $this->filled('canal') ? (string) $this->input('canal') : null,
            'pago'        => $this->filled('pago') ? (string) $this->input('pago') : null,
            'desde'       => $this->filled('desde') ? (string) $this->input('desde') : null,
            'hasta'       => $this->filled('hasta') ? (string) $this->input('hasta') : null,
        ];
    }
}
