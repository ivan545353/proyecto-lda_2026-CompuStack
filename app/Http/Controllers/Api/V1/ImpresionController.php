<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Impresion\ImpresionFirmada;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El listado impreso, abierto por el navegador con el enlace que pidió el endpoint del módulo (D-22).
 */
class ImpresionController extends Controller
{
    /** La firma ya la verificó el middleware `signed`. */
    public function descargar(string $listado, Request $request, ImpresionFirmada $impresion): Response
    {
        return $impresion->descarga($listado, $request->query());
    }
}
