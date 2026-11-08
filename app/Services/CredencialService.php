<?php

namespace App\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La credencial con la que la app de la tienda se identifica ante la API.
 *
 * Métodos:
 *   emitir()        crea una credencial nueva y devuelve su forma de docs/api.md §6.4
 *   revocarActual() cierra la sesión de este dispositivo
 *   revocarTodas()  cierra todas las sesiones de la persona (baja, desactivación)
 */
class CredencialService
{
    /** Nombre con el que queda registrada, para distinguirla de otras en la tabla. */
    private const NOMBRE = 'app-tienda';

    /**
     * @return array{credencial: string, tipo: string, vence: string,
     *               usuario: array{nombre: string, apellido: string, email: string}}
     *         Es el contenido de `datos`: no es la forma de Sanctum, y la app no la conoce.
     */
    public function emitir(User $usuario): array
    {
        $nueva = $usuario->createToken(
            self::NOMBRE,
            ['*'],
            now()->addMinutes((int) config('sanctum.expiration')),
        );

        return [
            'credencial' => $nueva->plainTextToken,
            'tipo'       => 'Bearer',
            'vence'      => $nueva->accessToken->expires_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'usuario'    => [
                'nombre'   => $usuario->nombre,
                'apellido' => $usuario->apellido,
                'email'    => $usuario->email,
            ],
        ];
    }

    /**
     * Revoca la credencial con la que llegó este pedido. Si el usuario no se
     * autenticó con una credencial (por ejemplo, un test con `actingAs`), no hay
     * nada que revocar.
     */
    public function revocarActual(User $usuario): void
    {
        $actual = $usuario->currentAccessToken();

        if ($actual instanceof PersonalAccessToken) {
            $actual->delete();
        }
    }

    /** Revoca todas las credenciales de la persona. */
    public function revocarTodas(User $usuario): void
    {
        $usuario->tokens()->delete();
    }
}
