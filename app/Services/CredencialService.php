<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La credencial con la que la app de la tienda se identifica ante la API.
 *
 * Métodos:
 *   iniciar()       verifica correo y contraseña de una cuenta de tienda y emite su credencial
 *   emitir()        crea una credencial nueva y devuelve su forma de docs/api.md §6.4
 *   revocarActual() cierra la sesión de este dispositivo
 *   revocarTodas()  cierra todas las sesiones de la persona (baja, desactivación)
 */
class CredencialService
{
    /** Nombre con el que queda registrada, para distinguirla de otras en la tabla. */
    private const NOMBRE = 'app-tienda';

    /**
     * Sin abrir sesión web. Inexistente, contraseña incorrecta, inactiva o de gestión dan el
     * mismo error: decir cuál falló permitiría enumerar cuentas (docs/api.md §4).
     *
     * @throws ValidationException
     */
    public function iniciar(string $email, string $password): array
    {
        $usuario = User::where('email', $email)->first();

        if ($usuario === null) {
            // Sin cuenta igual se calcula un hash: que el tiempo de respuesta tampoco la delate.
            Hash::make($password);
        }

        if ($usuario === null
            || ! Hash::check($password, $usuario->password)
            || ! $usuario->activo
            || $usuario->esDeGestion()) {
            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos.',
            ]);
        }

        return $this->emitir($usuario);
    }

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
