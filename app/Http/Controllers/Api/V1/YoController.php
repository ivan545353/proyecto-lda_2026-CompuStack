<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CambiarPasswordRequest;
use App\Http\Resources\Api\YoResource;
use App\Services\CredencialService;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La cuenta de la credencial (tramos B e I). Sin permiso propio: saber quién soy y cambiar la contraseña
 * propia son de toda cuenta (M-21).
 *
 * Métodos:
 *   show()        la cuenta, su rol y sus permisos
 *   contrasena()  cambia la contraseña propia y rota la credencial (HL-132)
 */
class YoController extends Controller
{
    /** La cuenta con la sesión, su rol y sus permisos. */
    public function show(Request $request): JsonResponse
    {
        return Recurso::respuesta(new YoResource($request->user()->load('rol')));
    }

    /**
     * El servicio revoca todas las credenciales de la cuenta, también la de este pedido: quien la cambia
     * porque se la sacaron espera que el otro quede afuera. Para no expulsar a quien hizo lo correcto, se
     * emite una nueva, con la misma forma que `POST /sesion`: es lo que era `session()->regenerate()`.
     */
    public function contrasena(CambiarPasswordRequest $request, UsuarioService $usuarios, CredencialService $credenciales): JsonResponse
    {
        $cuenta = $usuarios->cambiarPassword($request->user(), $request->validated('password'));

        return Recurso::respuesta(
            new JsonResource($credenciales->emitir($cuenta)),
            200,
            'Tu contraseña se cambió. La próxima vez que inicies sesión usá la nueva.',
        )->header('Cache-Control', 'no-store');
    }
}
