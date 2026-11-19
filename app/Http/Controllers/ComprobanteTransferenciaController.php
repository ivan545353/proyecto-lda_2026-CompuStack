<?php

namespace App\Http\Controllers;

use App\Models\ComprobanteTransferencia;
use App\Models\Venta;
use App\Services\TransferenciaService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El comprobante de transferencia que cargó el comprador, para quien revisa el pago (tramo L, D-27).
 * Se sirve por acá y no por `storage/`: el disco es privado y la ruta pide su permiso.
 */
class ComprobanteTransferenciaController extends Controller
{
    /** Lo abre en el navegador; de otra venta, 404. */
    public function show(Venta $venta, ComprobanteTransferencia $comprobante): StreamedResponse
    {
        abort_unless($comprobante->venta_id === $venta->id, 404);

        return Storage::disk(TransferenciaService::DISCO)->response(
            $comprobante->ruta,
            $comprobante->nombre_original,
            ['Content-Type' => $comprobante->tipo],
            'inline',
        );
    }
}
