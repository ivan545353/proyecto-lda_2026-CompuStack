<?php

namespace App\Http\Controllers;

use App\Http\Requests\UsuarioFiltroRequest;
use App\Http\Requests\UsuarioRequest;
use App\Models\Rol;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestión de personas del sistema: usuarios con su satélite.
 *
 * Reemplaza al UserController original, que era el peor del sistema:
 * devolvía hashes de contraseña (C-1), permitía la escalada de privilegios
 * (C-3) y sus filtros no filtraban (A-24).
 *
 * El controlador traduce entre HTTP y UsuarioService. No valida
 * (UsuarioRequest) ni decide reglas (UsuarioService) ni arma consultas de
 * negocio: encadena los scopes del modelo.
 *
 * Métodos:
 *   index()              listado filtrado y paginado
 *   create() / store()   alta, con el satélite que corresponda al rol
 *   edit()  / update()   edición; el rol NO es un campo de este formulario
 *   destroy()            baja física o lógica, según tenga historial
 */
class UsuarioController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function index(UsuarioFiltroRequest $request): View
    {
        $usuarios = User::query()
            // C-1, la parte que $hidden no cubre. $hidden sólo actúa al
            // serializar; en una vista Blade el hash se imprimiría igual. Acá
            // la columna ni siquiera se trae de la base. Es la corrección
            // literal del `SELECT SQL_CALC_FOUND_ROWS u.*` de UserDao::list().
            ->select(['id', 'nombre', 'apellido', 'email', 'rol_id', 'activo'])
            ->with(['rol:id,nombre,ambito', 'empleado:id,user_id,legajo,fecha_baja'])
            ->buscar($request->query('q'))
            ->deRol($request->query('rol_id'))
            ->deAmbito($request->query('ambito'))
            ->conEstado($request->query('estado'))
            ->conSituacion($request->query('situacion'))
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate(15)          // A-25: el original devolvía la tabla entera
            ->withQueryString();

        return view('usuarios.index', [
            'usuarios' => $usuarios,
            'roles'    => Rol::orderBy('nombre')->get(['id', 'nombre', 'ambito']),
        ]);
    }

    public function create(): View
    {
        return view('usuarios.form', [
            'usuario' => new User(['activo' => true]),
            'roles'   => Rol::orderBy('nombre')->get(['id', 'nombre', 'ambito', 'descripcion']),
        ]);
    }

    public function store(UsuarioRequest $request): RedirectResponse
    {
        $usuario = $this->service->crear($request->validated());

        return redirect()->route('usuarios.index')
            ->with('exito', "Se creó la cuenta de {$usuario->nombre_completo}.");
    }

    public function edit(User $usuario): View
    {
        return view('usuarios.form', [
            'usuario' => $usuario->load(['rol', 'empleado', 'cliente']),
            'roles'   => Rol::orderBy('nombre')->get(['id', 'nombre', 'ambito', 'descripcion']),
        ]);
    }

    public function update(UsuarioRequest $request, User $usuario): RedirectResponse
    {
        $seDesactiva = $usuario->activo && ! $request->boolean('activo');

        $usuario = $this->service->actualizar($usuario, $request->validated());

        // El mensaje dice lo que pasó de verdad. Que alguien pierda el acceso
        // es un efecto que hay que nombrar, no dejarlo escondido en un
        // "actualizado" genérico.
        return redirect()->route('usuarios.index')->with('exito', $seDesactiva
            ? "Se guardaron los datos de {$usuario->nombre_completo} y se le quitó el acceso al sistema."
            : "Se guardaron los datos de {$usuario->nombre_completo}.");
    }

    public function destroy(User $usuario): RedirectResponse
    {
        // Depende de quién está pidiendo, así que vive acá y no en el servicio.
        // Sin formulario no hay Form Request donde ponerlo.
        if ($usuario->is(auth()->user())) {
            return back()->with('error', 'No podés eliminar tu propia cuenta.');
        }

        $nombre  = $usuario->nombre_completo;
        $seBorro = $this->service->eliminar($usuario);

        return redirect()->route('usuarios.index')->with('exito', $seBorro
            ? "Se eliminó la cuenta de {$nombre}."
            : "{$nombre} tiene operaciones registradas: se le quitó el acceso en lugar de eliminar la cuenta, para no perder el historial.");
    }
}