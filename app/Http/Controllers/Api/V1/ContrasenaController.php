<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Api\Recurso;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RestablecerPasswordRequest;
use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Password as BrokerDePassword;
use Illuminate\Validation\ValidationException;

/**
 * Fijar la contraseña con el enlace de un solo uso (tramo I). Público: el token es la autorización, y lo
 * verifica el broker de Laravel (hasheado, con vencimiento) antes de llamar al servicio.
 *
 * No emite credencial: que la persona entre con la contraseña nueva confirma que quedó bien, y evita
 * abrirle sesión a una cuenta que el ingreso rechazaría por otro motivo (desactivada, o de la tienda en
 * la gestión). `datos.ambito` le dice a la pantalla adónde mandarla (D-26).
 *
 * Métodos:
 *   restablecer()  verifica el token y guarda la contraseña; un token inválido es 422 en `email`
 */
class ContrasenaController extends Controller
{
    public function __construct(private UsuarioService $service)
    {
    }

    public function restablecer(RestablecerPasswordRequest $request): JsonResponse
    {
        $cuenta = null;

        // El broker valida el token, llama al callback y después lo borra: el enlace sirve una sola vez.
        $estado = BrokerDePassword::reset(
            $request->validated(),
            function (User $usuario, string $password) use (&$cuenta) {
                $cuenta = $this->service->cambiarPassword($usuario, $password);
            },
        );

        // Vencido, ya usado o de otra cuenta caen todos acá, con el mensaje del broker (lang/es/passwords.php).
        if ($estado !== BrokerDePassword::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($estado)]);
        }

        $deGestion = $cuenta->esDeGestion();

        return Recurso::respuesta(
            new JsonResource(['ambito' => $cuenta->rol->ambito]),
            200,
            $deGestion
                ? 'Tu contraseña quedó configurada. Ya podés iniciar sesión.'
                : 'Tu contraseña quedó configurada. Ya podés entrar desde la app de la tienda con tu correo y la contraseña que elegiste.',
        );
    }
}
