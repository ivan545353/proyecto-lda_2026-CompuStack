<?php

namespace App\Http\Controllers;

use App\Http\Requests\CambiarRolRequest;
use App\Http\Requests\PersonalFiltroRequest;
use App\Http\Requests\PersonalRequest;
use App\Models\Rol;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Personal del sistema: las cuentas de ámbito gestión con sus datos laborales.
 *
 * Reemplaza al UserController original, que era el peor del sistema: devolvía
 * hashes de contraseña (C-1), permitía la escalada de privilegios (C-3) y sus
 * filtros no filtraban (A-24).
 *
 * Lista SÓLO personal. Antes el ámbito era un filtro opcional, y eso ponía al
 * cajero y a un cliente de la tienda en la misma lista sin que nada lo dijera:
 * un filtro que hay que acordarse de aplicar no separa nada. Las fichas de
 * clientes viven en ClienteController.
 *
 * Toda ruta que recibe una persona pasa por `soloPersonal()`. El binding
 * implícito de Laravel resuelve cualquier id de `users`, así que sin eso
 * /personal/{id}/editar abriría la cuenta de un cliente de la tienda desde la
 * pantalla del personal. Es el mismo descuido que el de las direcciones
 * anidadas, y se corrige igual: acotando la búsqueda en lugar de confiar en el
 * parámetro.
 *
 * Métodos:
 *   index()                            listado filtrado y paginado
 *   create() / store()                 alta, con su fila en empleados
 *   edit()  / update()                 edición; el rol NO es un campo
 *   destroy()                          baja física o lógica, según historial
 *   editarRol() / cambiarRol()         acción separada, con permiso propio
 *   generarEnlaceDeRestablecimiento()  enlace de un solo uso
 */
class PersonalController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function index(PersonalFiltroRequest $request): View
    {
        $personal = User::query()
            // C-1, la parte que $hidden no cubre. $hidden sólo actúa al
            // serializar; en una vista Blade el hash se imprimiría igual. Acá la
            // columna ni siquiera se trae de la base. Es la corrección literal
            // del `SELECT SQL_CALC_FOUND_ROWS u.*` de UserDao::list().
            ->select(['id', 'nombre', 'apellido', 'email', 'rol_id', 'activo'])
            ->with(['rol:id,nombre', 'empleado:id,user_id,legajo,fecha_baja'])
            // Siempre, no como filtro. Es lo que hace que esta pantalla sea la
            // del personal y no la de todas las cuentas del sistema.
            ->deAmbito('gestion')
            ->buscar($request->query('q'))
            ->deRol($request->query('rol_id'))
            ->conEstado($request->query('estado'))
            ->conSituacion($request->query('situacion'))
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate(15)          // A-25: el original devolvía la tabla entera
            ->withQueryString();    // sin esto, la página 2 pierde los filtros

        return view('personal.index', [
            'personal' => $personal,
            'usuarios' => $personal,
            'roles'    => $this->rolesDeGestion(),
        ]);
    }

    public function create(): View
    {
        return view('personal.form', [
            'usuario' => new User(['activo' => true]),
            'roles'   => $this->rolesDeGestion(),
        ]);
    }

    public function store(PersonalRequest $request): RedirectResponse
    {
        $usuario = $this->service->crear($request->validated());

        return redirect()->route('personal.index')
            ->with('exito', "Se creó la cuenta de {$usuario->nombre_completo}.");
    }

    public function edit(User $usuario): View
    {
        $usuario = $this->soloPersonal($usuario);

        return view('personal.form', [
            'usuario' => $usuario->load(['rol', 'empleado']),
            'roles'   => $this->rolesDeGestion(),
        ]);
    }

    public function update(PersonalRequest $request, User $usuario): RedirectResponse
    {
        $usuario = $this->soloPersonal($usuario);

        $seDesactiva = $usuario->activo && ! $request->boolean('activo');

        $usuario = $this->service->actualizar($usuario, $request->validated());

        // El mensaje dice lo que pasó de verdad. Que alguien pierda el acceso es
        // un efecto que hay que nombrar, no esconder en un "actualizado".
        return redirect()->route('personal.index')->with('exito', $seDesactiva
            ? "Se guardaron los datos de {$usuario->nombre_completo} y se le quitó el acceso al sistema."
            : "Se guardaron los datos de {$usuario->nombre_completo}.");
    }

    public function destroy(User $usuario): RedirectResponse
    {
        $usuario = $this->soloPersonal($usuario);

        // Depende de quién está pidiendo, así que vive acá y no en el servicio.
        // Sin formulario no hay Form Request donde ponerlo.
        if ($usuario->is(auth()->user())) {
            return back()->with('error', 'No podés eliminar tu propia cuenta.');
        }

        $nombre  = $usuario->nombre_completo;
        $seBorro = $this->service->eliminar($usuario);

        return redirect()->route('personal.index')->with('exito', $seBorro
            ? "Se eliminó la cuenta de {$nombre}."
            : "{$nombre} tiene operaciones registradas: se le quitó el acceso en lugar de eliminar la cuenta, para no perder el historial.");
    }

    public function editarRol(User $usuario): View
    {
        $usuario = $this->soloPersonal($usuario);

        return view('personal.rol', [
            'usuario' => $usuario->load('rol.permisos'),
            'roles'   => $this->service->rolesAsignablesA($usuario),
        ]);
    }

    public function cambiarRol(CambiarRolRequest $request, User $usuario): RedirectResponse
    {
        $usuario = $this->soloPersonal($usuario);

        $usuario = $this->service->cambiarRol($usuario, $request->integer('rol_id'));

        return redirect()->route('personal.edit', $usuario)->with(
            'exito',
            "{$usuario->nombre_completo} pasó a tener el rol {$usuario->rol->nombre}. "
            .'El cambio rige a partir de su próxima acción en el sistema.'
        );
    }

    public function generarEnlaceDeRestablecimiento(User $usuario): RedirectResponse
    {
        $usuario = $this->soloPersonal($usuario);

        // No es sólo que no tenga sentido teniendo la pantalla de cambio propio:
        // un enlace para la propia cuenta deja una credencial viva de la cuenta
        // más privilegiada en el portapapeles y en el historial, sin beneficio.
        if ($usuario->is(auth()->user())) {
            return back()->with(
                'error',
                'Para tu propia cuenta usá «Cambiar mi contraseña»: la sabés, no te hace falta un enlace.'
            );
        }

        $token = $this->service->crearTokenDeRestablecimiento($usuario);

        // El enlace se arma acá y no en el servicio: una URL es de la capa HTTP.
        $enlace = route('password.reset', ['token' => $token, 'email' => $usuario->email]);

        return redirect()->route('personal.edit', $usuario)
            ->with('enlace_restablecimiento', $enlace)
            ->with('nombre_restablecido', $usuario->nombre_completo);
    }

    /**
     * Se asegura de que la cuenta sea del personal.
     *
     * Una cuenta de la tienda da 404 acá: no es que no se pueda editar, es que
     * esta pantalla no es la suya. Sin esto el binding implícito resolvería
     * cualquier fila de `users`.
     */
    private function soloPersonal(User $usuario): User
    {
        abort_unless($usuario->esDeGestion(), 404);

        return $usuario;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Rol> */
    private function rolesDeGestion()
    {
        return Rol::where('ambito', 'gestion')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'ambito', 'descripcion']);
    }
}