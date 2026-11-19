<?php

namespace App\Http\Resources\Api;

use App\Models\Cliente;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La cuenta de la persona con sesión. El documento se edita desde la app (tramo K); la condición
 * frente al IVA viaja para verse, pero la carga el local (D-22). La dirección predeterminada se
 * propone en el envío (tramo F); editarla para un pedido no la cambia acá.
 */
class PerfilResource extends JsonResource
{
    public function toArray($request): array
    {
        $cliente = $this->cliente;

        // Sin número no hay documento: la columna `tipo_doc` no admite vacío y el registro guarda
        // `dni` de relleno, que la app mostraría como un dato que nadie cargó (HL-65).
        $tipoDoc = blank($cliente?->nro_doc) ? null : $cliente->tipo_doc;

        return [
            'nombre'              => $this->nombre,
            'apellido'            => $this->apellido,
            'email'               => $this->email,
            'telefono'            => $cliente?->telefono,
            'razon_social'        => $cliente?->razon_social,
            'tipo_doc'            => $tipoDoc,
            'tipo_doc_texto'      => $tipoDoc === null ? null : Cliente::TIPOS_DOC[$tipoDoc],
            'nro_doc'             => $tipoDoc === null ? null : $cliente->nro_doc,
            'condicion_iva'       => $cliente?->condicion_iva,
            'condicion_iva_texto' => $cliente === null ? null : Cliente::CONDICIONES_IVA[$cliente->condicion_iva],
            'direccion'           => ($d = $cliente?->direccionPredeterminada) === null ? null : [
                'calle'         => $d->calle,
                'numero'        => $d->numero,
                'piso_depto'    => $d->piso_depto,
                'codigo_postal' => $d->codigo_postal,
                'localidad'     => $d->localidad,
                'provincia'     => $d->provincia,
            ],
        ];
    }
}
