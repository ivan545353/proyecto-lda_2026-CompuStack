<?php

namespace App\Http\Controllers;

use App\Http\Requests\RolRequest;
use App\Models\Permiso;
use App\Models\Rol;
use App\Services\RolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
/**
 * Administración de roles y permisos.
 *
 * Módulo que pide la consigna de la Etapa 1: el administrador tiene que poder
 * ver y modificar qué hace cada rol sin que nadie recompile nada.
 *
 * El controlador sólo traduce entre HTTP y RolService. No valida (eso es
 * RolRequest) ni decide reglas de negocio (eso es el servicio). Cuando en la
 * Etapa 3 exista la API, su controlador llamará al mismo servicio y devolverá
 * JSON en lugar de vistas.
 *
 * Métodos:
 *   index()              listado paginado con conteo de permisos y usuarios
 *   create() / store()   alta
 *   edit()  / update()   edición
 *   destroy()            baja
 *   permisosPorModulo()  los permisos agrupados, como se ven en pantalla
 */
class RolController extends Controller
{
    public function __construct(private RolService $service)
    {
    }

    public function index(): View
    {
        $roles = Rol::query()
            ->withCount(['permisos', 'usuarios'])
            ->orderByDesc('es_sistema')
            ->orderBy('nombre')
            ->paginate(15);

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        return view('roles.form', [
            'rol'              => new Rol(['ambito' => 'gestion']),
            'permisos'         => $this->permisosPorModulo(),
            'permisosActuales' => [],
        ]);
    }

    public function store(RolRequest $request): RedirectResponse
    {
        $rol = $this->service->crear($request->validated());

        return redirect()->route('roles.index')
            ->with('exito', "Rol «{$rol->nombre}» creado.");
    }

    public function edit(Rol $rol): View
    {
        return view('roles.form', [
            'rol'              => $rol,
            'permisos'         => $this->permisosPorModulo(),
            'permisosActuales' => $rol->permisos()->pluck('permisos.id')->all(),
        ]);
    }

    public function update(RolRequest $request, Rol $rol): RedirectResponse
    {
        $this->service->actualizar($rol, $request->validated(), $request->user());

        return redirect()->route('roles.index')
            ->with('exito', "Rol «{$rol->nombre}» actualizado.");
    }

    public function destroy(Rol $rol): RedirectResponse
    {
        $this->service->eliminar($rol);

        return redirect()->route('roles.index')
            ->with('exito', 'Rol eliminado.');
    }

    /** Los permisos agrupados por módulo, que es como se presentan en pantalla. */
    private function permisosPorModulo()
    {
        return Permiso::orderBy('modulo')->orderBy('clave')->get()->groupBy('modulo');
    }
}