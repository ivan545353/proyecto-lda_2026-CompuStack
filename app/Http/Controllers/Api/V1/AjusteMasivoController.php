<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AjusteMasivoRequest;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Resources\Api\LoteAjusteResource;
use App\Http\Resources\Api\ProyeccionAjusteResource;
use App\Models\LoteAjuste;
use App\Services\AjusteMasivoService;
use Illuminate\Http\JsonResponse;

/**
 * El ajuste masivo de precios y su historial (tramo F): traduce entre el contrato y AjusteMasivoService.
 * El permiso lo pide la ruta.
 *
 * «No hizo falta hacer nada» (el servicio devuelve null) es un 409 `regla_de_negocio` con el aviso: no
 * se escribió ningún lote, y un 201 afirmaría algo que no pasó (regla 16, HL-122).
 *
 * Métodos:
 *   vistaPrevia()  los conteos del conjunto entero y una página de la tabla «precio actual → nuevo»; no escribe
 *   store()        aplica el ajuste y devuelve el lote (201)
 *   lotes()        el historial, del más nuevo al más viejo, con cuál se puede deshacer
 *   deshacer()     escribe el lote que devuelve los precios (201); sólo el último reversible
 */
class AjusteMasivoController extends Controller
{
    public function __construct(private AjusteMasivoService $service)
    {
    }

    /** El sobre de un listado, más `conteos` y `criterio_texto`: lo que la pantalla dice antes de aplicar. */
    public function vistaPrevia(AjusteMasivoRequest $request): JsonResponse
    {
        $datos = $request->ajuste();

        $pagina = $this->service->consulta($datos)
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina())
            ->through(fn ($producto) => [
                'producto'   => $producto,
                'proyeccion' => $this->service->proyectar($producto, $datos),
            ]);

        return Paginado::respuesta($pagina, ProyeccionAjusteResource::class, [], [
            'conteos'        => $this->service->previsualizar($datos),
            'criterio_texto' => $this->service->criterioTexto($datos),
        ]);
    }

    public function store(AjusteMasivoRequest $request): JsonResponse
    {
        $lote = $this->service->aplicar($request->ajuste(), $request->user());

        if ($lote === null) {
            throw new ReglaDeNegocioException(
                'El ajuste corrió y no cambió ningún precio: no hubo productos a los que '
                .'aplicarlo. Revisá el recorte, y si los que buscabas tienen el precio fijado '
                .'a mano, marcá la casilla para incluirlos.'
            );
        }

        $mensaje = "Ajuste aplicado a {$lote->alcanzados} producto(s). Se puede deshacer.";

        if ($lote->omitidos > 0) {
            $mensaje .= " Quedaron {$lote->omitidos} sin cambios, por no tener costo cargado o "
                .'porque el precio nuevo era el que ya tenían.';
        }

        return Recurso::respuesta(new LoteAjusteResource($lote->load(['usuario', 'revertido', 'reversion'])), 201, $mensaje);
    }

    /**
     * Sin filtros, a propósito: lo que se busca es el último, que es el único que se puede deshacer. El
     * sobre suma `reversible` (ese lote, o null) para que la pantalla lo ofrezca esté en la página que esté.
     */
    public function lotes(PaginadoRequest $request): JsonResponse
    {
        $ultimo     = LoteAjuste::ultimoReversible()?->load(['usuario', 'reversion', 'revertido']);
        $reversible = $ultimo?->id;

        $pagina = LoteAjuste::query()
            ->with(['usuario', 'reversion', 'revertido'])
            ->recientes()
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        $pagina->getCollection()->each(fn (LoteAjuste $lote) => $lote->setAttribute('es_reversible', $lote->id === $reversible));

        return Paginado::respuesta($pagina, LoteAjusteResource::class, [], [
            'reversible' =>$ultimo === null ? null : (new LoteAjusteResource($ultimo->setAttribute('es_reversible', true)))->resolve(),
        ]);
    }

    /** Si no es el último reversible, el servicio lanza la regla y el manejador la vuelve 409. */
    public function deshacer(LoteAjuste $lote): JsonResponse
    {
        $reversion = $this->service->deshacer($lote, request()->user());

        if ($reversion === null) {
            throw new ReglaDeNegocioException(
                'No se devolvió ningún precio: todos los productos de ese ajuste cambiaron de '
                .'precio después, y devolverlos pisaría esos cambios. El ajuste sigue estando y '
                .'se puede volver a intentar.'
            );
        }

        $mensaje = "Se devolvieron los precios de {$reversion->alcanzados} producto(s).";

        if ($reversion->omitidos > 0) {
            $mensaje .= " Quedaron {$reversion->omitidos} sin devolver, porque su precio cambió "
                .'después del ajuste y devolverlo pisaría ese cambio.';
        }

        return Recurso::respuesta(new LoteAjusteResource($reversion->load(['usuario', 'revertido', 'reversion'])), 201, $mensaje);
    }
}
