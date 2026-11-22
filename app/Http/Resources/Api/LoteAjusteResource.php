<?php

namespace App\Http\Resources\Api;

use App\Models\LoteAjuste;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un lote de ajuste masivo (tramo F), con su criterio congelado y su resultado. Los parámetros van como
 * cadena (null si la operación no los lleva) y además escritos en `parametro_texto`.
 *
 * `revierte_a` y `deshecho_el` viajan si se cargaron las relaciones; `reversible` sólo si el controlador
 * lo calculó (`es_reversible`): es el último lote que se puede deshacer, y lo dice el servidor para que la
 * pantalla no ofrezca un botón que el servicio va a rechazar.
 */
class LoteAjusteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'operacion'         => $this->operacion,
            'operacion_texto'   => LoteAjuste::OPERACIONES[$this->operacion] ?? $this->operacion,
            'porcentaje'        => $this->porcentaje,
            'ganancia'          => $this->ganancia,
            'redondeo_multiplo' => $this->redondeo_multiplo,
            'parametro_texto'   => $this->parametro(),
            'criterio_texto'    => $this->criterio_texto,
            'alcanzados'        => (int) $this->alcanzados,
            'omitidos'          => (int) $this->omitidos,
            'usuario'           => $this->whenLoaded('usuario', fn () => $this->usuarioTexto()),
            'fecha'             => self::iso($this->created_at),
            'revierte_a'        => $this->whenLoaded('revertido', fn () => $this->revertido === null ? null : [
                'id'    => $this->revertido->id,
                'fecha' => self::iso($this->revertido->created_at),
            ]),
            'deshecho_el'       => $this->whenLoaded('reversion', fn () => self::iso($this->reversion?->created_at)),
            'reversible'        => $this->whenHas('es_reversible', fn () => (bool) $this->es_reversible),
        ];
    }

    private static function iso($momento): ?string
    {
        return $momento?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
