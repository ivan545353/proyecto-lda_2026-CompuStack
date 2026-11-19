<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ComprobanteTransferencia;
use App\Models\Venta;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * El comprobante de una transferencia de la tienda (tramo L, D-27).
 *
 * Cargarlo no cobra nada: deja el pedido «en revisión» (regla 9) y estira la reserva a los días
 * hábiles que la tienda se toma para confirmar. El cobro es el de siempre, en la web, por
 * `PagoService::cobrar()` con método `transferencia`: ahí sale el stock (regla 5).
 *
 * Métodos:
 *   cargarComprobante()  guarda el archivo y su fila; rechaza lo que no espera una transferencia
 */
class TransferenciaService
{
    public const DISCO = 'local';

    /** @throws ReglaDeNegocioException */
    public function cargarComprobante(Venta $venta, UploadedFile $archivo): Venta
    {
        $ruta = null;

        try {
            return DB::transaction(function () use ($venta, $archivo, &$ruta) {
                // Bloqueo antes de leer el estado (regla 7): un cobro en la web no se cruza con la carga.
                $v = Venta::lockForUpdate()->findOrFail($venta->id);

                $this->exigirQueEspereComprobante($v);

                $ruta = $archivo->store("comprobantes-transferencia/{$v->id}", self::DISCO);

                $comprobante = new ComprobanteTransferencia;
                $comprobante->venta_id        = $v->id;
                $comprobante->ruta            = $ruta;
                $comprobante->nombre_original = mb_substr($archivo->getClientOriginalName(), 0, 255);
                $comprobante->tipo            = (string) $archivo->getMimeType();
                $comprobante->tamano          = (int) $archivo->getSize();
                $comprobante->save();

                // La reserva cubre lo que la tienda tarda en confirmar; nunca se acorta.
                $hasta = now()->addWeekdays((int) config('tienda.transferencia.dias_habiles_confirmacion'));
                if ($hasta->greaterThan($v->reserva_vence)) {
                    $v->reserva_vence = $hasta;
                    $v->save();
                }

                return $v;
            });
        } catch (Throwable $e) {
            // Sin fila no hay comprobante: el archivo suelto no queda en el disco.
            if ($ruta !== null) {
                Storage::disk(self::DISCO)->delete($ruta);
            }

            throw $e;
        }
    }

    /** @throws ReglaDeNegocioException */
    private function exigirQueEspereComprobante(Venta $v): void
    {
        $motivo = match (true) {
            $v->medio_pago !== 'transferencia' => 'no se paga por transferencia',
            $v->estado !== 'pendiente_pago'    => "está «{$v->estadoTexto()}» y ya no espera un pago",
            // Con la hora pasada vale mientras nadie la haya liberado (HL-10).
            $v->reserva_vence === null         => 'ya no tiene la mercadería reservada',
            default                            => null,
        };

        if ($motivo !== null) {
            throw new ReglaDeNegocioException(
                "El pedido {$v->numeroFormateado()} {$motivo}, así que no hace falta un comprobante."
            );
        }
    }
}
