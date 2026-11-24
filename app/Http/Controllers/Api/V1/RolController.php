<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PaginadoRequest;
use App\Http\Requests\Api\RolRequest;
use App\Http\Resources\Api\RolResource;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\RolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Roles y permisos (tramo I): traduce entre el contrato y RolService. El permiso lo pide la ruta. Las tres
 * protecciones del módulo (el rol propio, los de sistema y los que tienen usuarios) son del servicio, y
 * llegan como 409.
 *
 * Métodos:
 *   index()     listado paginado, los de sistema primero, con cuántos permisos y usuarios tiene cada uno
 *   permisos()  el catálogo de permisos, para el formulario (que los agrupa por módulo)
 *   show()      la ficha, con los ids de sus permisos
 *   store()     alta (201)
 *   update()    edición; el autor es quien pide, para la protección del rol propio
 *   destroy()   baja
 */
class RolController extends Controller
{
    public const POR_PAGINA = 15;

    public function __construct(private RolService $service)
    {
    }

    public function index(PaginadoRequest $request): JsonResponse
    {
        $pagina = Rol::query()
            ->withCount(['permisos', 'usuarios'])
            ->orderByDesc('es_sistema')
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($request->integer('por_pagina', self::POR_PAGINA), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, RolResource::class, []);
    }

    public function permisos(PaginadoRequest $request): JsonResponse
    {
        $pagina = Permiso::query()
            ->orderBy('modulo')
            ->orderBy('clave')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'clave', 'modulo', 'descripcion'], 'pagina', $request->pagina())
            ->through(fn (Permiso $permiso) => [
                'id'          => $permiso->id,
                'clave'       => $permiso->clave,
                'modulo'      => $permiso->modulo,
                'descripcion' => $permiso->descripcion,
            ]);

        return Paginado::respuesta($pagina, JsonResource::class, []);
    }

    public function show(Rol $rol): JsonResponse
    {
        return Recurso::respuesta($this->ficha($rol));
    }

    public function store(RolRequest $request): JsonResponse
    {
        $rol = $this->service->crear($request->validated());

        return Recurso::respuesta($this->ficha($rol), 201, "Rol «{$rol->nombre}» creado.");
    }

    public function update(RolRequest $request, Rol $rol): JsonResponse
    {
        $rol = $this->service->actualizar($rol, $request->validated(), $request->user());

        return Recurso::respuesta($this->ficha($rol), 200, "Rol «{$rol->nombre}» actualizado.");
    }

    public function destroy(Rol $rol): JsonResponse
    {
        $id     = $rol->id;
        $nombre = $rol->nombre;

        $this->service->eliminar($rol);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => true]),
            200,
            "Rol «{$nombre}» eliminado.",
        );
    }

    private function ficha(Rol $rol): RolResource
    {
        return new RolResource($rol->load('permisos')->loadCount(['permisos', 'usuarios']));
    }
}
