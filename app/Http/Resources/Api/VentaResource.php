<?php

namespace App\Http\Resources\Api;

use App\Support\Importe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una venta en el listado de la administración (tramo H). `editable` lo decide el modelo: sólo un
 * presupuesto se edita. `sin_stock` es la insignia del listado: un presupuesto con algún renglón que
 * supera el disponible de hoy, contado por la base (`faltantes_count`, A-26); una venta cobrada no la
 * lleva aunque el stock ya no alcance, porque esa mercadería ya salió. Las dos claves sólo vienen en
 * el listado, que es el que cuenta.
 */
class VentaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'numero'       => $this->numeroFormateado(),
            'fecha'        => self::fecha($this->created_at),
            'cliente'      => $this->cliente ? [
                'id'           => $this->cliente->id,
                'razon_social' => $this->cliente->razon_social,
            ] : null,
            // `cliente` en null SIGNIFICA sin identificar: el texto lo dice con palabras.
            'cliente_texto' => $this->clienteTexto(),
            'vendedor'     => $this->usuario?->nombre_completo,
            'canal'        => $this->canal,
            'canal_texto'  => $this->canalTexto(),
            'estado'       => $this->estado,
            'estado_texto' => $this->estadoTexto(),
            'total'        => Importe::json($this->total),
            'editable'     => $this->esPresupuesto(),
            'productos'    => $this->whenCounted('lineas'),
            // El conteo viene para todas; la insignia es sólo del presupuesto.
            'renglones_sin_stock' => $this->when($this->faltantes_count !== null, fn () => (int) $this->faltantes_count),
            'sin_stock'    => $this->when(
                $this->faltantes_count !== null,
                fn () => $this->esPresupuesto() && (int) $this->faltantes_count > 0,
            ),
        ];
    }

    public static function fecha($momento): ?string
    {
        return $momento?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
