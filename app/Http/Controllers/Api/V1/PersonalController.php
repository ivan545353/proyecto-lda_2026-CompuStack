<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Api\Paginado;
use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CambiarRolRequest;
use App\Http\Requests\Api\PersonalFiltroRequest;
use App\Http\Requests\Api\PersonalRequest;
use App\Http\Requests\Api\RolesDelPersonalRequest;
use App\Http\Resources\Api\PersonalResource;
use App\Models\Rol;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El personal (tramo I): traduce entre el contrato y UsuarioService. El permiso lo pide la ruta.
 *
 * Lista y abre SÓLO cuentas de gestión: el binding resuelve cualquier fila de `users`, así que toda
 * ruta que recibe una persona pasa por `soloPersonal()` (o por el `authorize()` de su Form Request), y
 * una cuenta de la tienda es 404, como en la web.
 *
 * Dos reglas dependen de quién pide y no del dato, y por eso siguen acá y no en el servicio: nadie
 * elimina su propia cuenta ni se genera un enlace para sí. Son 409 con el mismo texto que la web.
 *
 * Métodos:
 *   index()      listado filtrado y paginado
 *   roles()      los roles del personal, para el filtro y el alta
 *   show()       la ficha; con `usuario.cambiar_rol`, también el rol actual y los asignables
 *   store()      alta, con su fila en `empleados` (201)
 *   update()     edición; el rol y la contraseña no son campos
 *   destroy()    baja física, o lógica si tiene historial
 *   cambiarRol() acción aparte, con permiso propio
 *   restablecer()  el enlace de un solo uso para que la persona fije su contraseña
 */
class PersonalController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function index(PersonalFiltroRequest $request): JsonResponse
    {
        $pagina = User::query()
            // C-1: el hash ni se trae de la base.
            ->select(['id', 'nombre', 'apellido', 'email', 'rol_id', 'activo'])
            ->with(['rol:id,nombre', 'empleado'])
            ->deAmbito('gestion')
            ->buscar($request->validated('q'))
            ->deRol($request->validated('rol_id'))
            ->conEstado($request->validated('estado'))
            ->conSituacion($request->validated('situacion'))
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['*'], 'pagina', $request->pagina());

        return Paginado::respuesta($pagina, PersonalResource::class, $request->criterios());
    }

    public function roles(RolesDelPersonalRequest $request): JsonResponse
    {
        $pagina = Rol::query()
            ->where('ambito', 'gestion')
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate($request->porPagina(), ['id', 'nombre', 'descripcion'], 'pagina', $request->pagina())
            ->through(fn (Rol $rol) => [
                'id'          => $rol->id,
                'texto'       => $rol->nombre,
                'descripcion' => $rol->descripcion,
            ]);

        return Paginado::respuesta($pagina, JsonResource::class, []);
    }

    public function show(User $usuario): JsonResponse
    {
        return Recurso::respuesta($this->ficha($this->soloPersonal($usuario)));
    }

    public function store(PersonalRequest $request): JsonResponse
    {
        $usuario = $this->service->crear($request->validated());

        return Recurso::respuesta(
            $this->ficha($usuario),
            201,
            "Se creó la cuenta de {$usuario->nombre_completo}.",
        );
    }

    public function update(PersonalRequest $request, User $usuario): JsonResponse
    {
        $seDesactiva = $usuario->activo && ! $request->validated('activo');

        $usuario = $this->service->actualizar($usuario, $request->validated());

        // Que alguien pierda el acceso es un efecto que se nombra, no se esconde en un «se guardó».
        return Recurso::respuesta($this->ficha($usuario), 200, $seDesactiva
            ? "Se guardaron los datos de {$usuario->nombre_completo} y se le quitó el acceso al sistema."
            : "Se guardaron los datos de {$usuario->nombre_completo}.");
    }

    public function destroy(Request $request, User $usuario): JsonResponse
    {
        $usuario = $this->soloPersonal($usuario);

        if ($usuario->is($request->user())) {
            throw new ReglaDeNegocioException('No podés eliminar tu propia cuenta.');
        }

        $id      = $usuario->id;
        $nombre  = $usuario->nombre_completo;
        $seBorro = $this->service->eliminar($usuario);

        return Recurso::respuesta(
            new JsonResource(['id' => $id, 'eliminado' => $seBorro]),
            200,
            $seBorro
                ? "Se eliminó la cuenta de {$nombre}."
                : "{$nombre} tiene operaciones registradas: se le quitó el acceso en lugar de eliminar la cuenta, para no perder el historial.",
        );
    }

    public function cambiarRol(CambiarRolRequest $request, User $usuario): JsonResponse
    {
        $usuario = $this->service->cambiarRol($usuario, $request->integer('rol_id'));

        return Recurso::respuesta(
            $this->ficha($usuario),
            200,
            "{$usuario->nombre_completo} pasó a tener el rol {$usuario->rol->nombre}. "
            .'El cambio rige a partir de su próxima acción en el sistema.',
        );
    }

    /**
     * El enlace es un secreto de un solo uso: viaja sólo en esta respuesta, que ninguna caché guarda
     * (como el del acceso a la tienda). Una URL es de la capa HTTP: se arma acá, no en el servicio.
     */
    public function restablecer(Request $request, User $usuario): JsonResponse
    {
        $usuario = $this->soloPersonal($usuario);

        // Un enlace para la propia cuenta deja una credencial viva de la cuenta más privilegiada en el
        // portapapeles y en el historial, sin beneficio: la contraseña propia se cambia sabiéndola.
        if ($usuario->is($request->user())) {
            throw new ReglaDeNegocioException(
                'Para tu propia cuenta usá «Cambiar mi contraseña»: la sabés, no te hace falta un enlace.'
            );
        }

        $token = $this->service->crearTokenDeRestablecimiento($usuario);

        return Recurso::respuesta(
            new JsonResource([
                'id'                      => $usuario->id,
                'nombre'                  => $usuario->nombre_completo,
                'enlace_restablecimiento' => route('password.reset', ['token' => $token, 'email' => $usuario->email]),
            ]),
            200,
            "Enlace generado para {$usuario->nombre_completo}. Sirve una sola vez.",
        )->header('Cache-Control', 'no-store');
    }

    /** Una cuenta de la tienda no es de esta pantalla: 404, no «no se puede». */
    private function soloPersonal(User $usuario): User
    {
        abort_unless($usuario->esDeGestion(), 404);

        return $usuario;
    }

    private function ficha(User $usuario): PersonalResource
    {
        return new PersonalResource($usuario->load(['rol.permisos', 'empleado']), ficha: true);
    }
}
