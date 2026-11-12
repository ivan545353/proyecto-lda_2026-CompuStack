<?php

namespace App\Http\Requests\Api;

use App\Models\Direccion;
use App\Support\Api\TextosTienda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Confirmar el pedido (`POST /pedidos`). Nombra el carrito, que es la clave de la idempotencia
 * (D-8). Los importes no se mandan: los calcula la tienda con la lista del medio (M-31).
 */
class ConfirmarPedidoRequest extends FormRequest
{
    public const CAMPOS_DIRECCION = [
        'calle' => 150, 'numero' => 10, 'piso_depto' => 20,
        'codigo_postal' => 10, 'localidad' => 100, 'provincia' => 100,
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Sin envío habilitado, pedirlo se rechaza: no se corrige a retiro (M-31).
        $modos = TextosTienda::modosEntrega();

        $direccion = [];
        foreach (self::CAMPOS_DIRECCION as $campo => $largo) {
            $direccion["direccion.{$campo}"] = $campo === 'piso_depto'
                ? ['nullable', 'string', "max:{$largo}"]
                : ['required_if:modo_entrega,envio', 'string', "max:{$largo}"];
        }
        $direccion['direccion.provincia'][] = Rule::in(Direccion::PROVINCIAS);   // la lista de la web (HL-69)

        return [
            'carrito_id'   => ['required', 'integer'],
            'medio_pago'   => ['required', 'string', Rule::in(['contado', 'pasarela'])],
            'modo_entrega' => ['required', 'string', Rule::in($modos)],
            // Con retiro, una dirección no tiene adónde ir: se rechaza en vez de ignorarla.
            'direccion'    => ['required_if:modo_entrega,envio', 'prohibited_unless:modo_entrega,envio', 'array'],
            ...$direccion,
            ...array_fill_keys([...CarritoCantidadRequest::IMPORTES, 'costo_envio'], ['prohibited']),
        ];
    }

    public function messages(): array
    {
        return [
            '*.prohibited'                => 'El precio lo calcula la tienda: no se envía.',
            'direccion.prohibited_unless' => 'Con retiro en el local no hace falta una dirección.',
            'modo_entrega.in'             => 'Elegí retiro en el local'.(config('tienda.envio.habilitado') ? ' o envío.' : ': la tienda no hace envíos.'),
            'medio_pago.in'               => 'Elegí pagar de contado o por Mercado Pago.',
            'direccion.*.required_if'     => 'Completá este dato de la dirección del envío.',
            'direccion.required_if'       => 'Para el envío necesitamos la dirección.',
            'direccion.provincia.in'      => 'Elegí una provincia de la lista.',
        ];
    }

    /** Los datos que confirma el servicio, uno por uno. */
    public function datos(): array
    {
        $envio = $this->input('modo_entrega') === 'envio';

        return [
            'carrito_id'   => (int) $this->input('carrito_id'),
            'medio_pago'   => (string) $this->input('medio_pago'),
            'modo_entrega' => (string) $this->input('modo_entrega'),
            'direccion'    => $envio ? [
                'calle'         => (string) $this->input('direccion.calle'),
                'numero'        => (string) $this->input('direccion.numero'),
                'piso_depto'    => $this->input('direccion.piso_depto'),
                'codigo_postal' => (string) $this->input('direccion.codigo_postal'),
                'localidad'     => (string) $this->input('direccion.localidad'),
                'provincia'     => (string) $this->input('direccion.provincia'),
            ] : null,
        ];
    }
}
