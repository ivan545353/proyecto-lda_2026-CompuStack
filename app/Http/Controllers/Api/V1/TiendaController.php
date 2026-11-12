<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TiendaResource;
use App\Support\Api\TextosTienda;
use Illuminate\Http\JsonResponse;

class TiendaController extends Controller
{
    /** Lo que la tienda ofrece hoy: los modos de entrega, sin precios. */
    public function show(): JsonResponse
    {
        return Recurso::respuesta(new TiendaResource(TextosTienda::modosEntrega()));
    }
}
