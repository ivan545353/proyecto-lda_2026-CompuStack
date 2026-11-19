<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * La credencial con la que un cliente —la app de la tienda o la administración— se identifica
 * ante la API. Es la única clase que sabe que existe Sanctum.
 *
 * Métodos:
 *   iniciar()       verifica correo y contraseña de una cuenta activa y emite su credencial
 *   emitir()        crea una credencial nueva y devuelve su forma de docs/api.md §6.4
 *   revocarActual() cierra la sesión de este dispositivo
 *   revocarTodas()  cierra todas las sesiones de la persona (baja, desactivación)
 *   vencio()        si una credencial que no sirvió existe y la venció el tiempo
 */
class CredencialService
{
    /** Nombre con el que queda registrada, por ámbito, para distinguirlas en la tabla. */
    private const NOMBRES = ['tienda' => 'app-tienda', 'gestion' => 'administracion'];

    /**
     * Sin abrir sesión web. Entrar es de toda cuenta activa; lo que puede hacer lo deciden sus
     * permisos (HL-98). Inexistente, contraseña incorrecta o inactiva dan el mismo error: decir
     * cuál falló permitiría enumerar cuentas (docs/api.md §4).
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
            || ! $usuario->activo) {
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
        // La vigencia sale del ámbito de la cuenta, que es un dato, y no del cliente que dice ser (D-31).
        $deGestion = $usuario->esDeGestion();

        $nueva = $usuario->createToken(
            self::NOMBRES[$deGestion ? 'gestion' : 'tienda'],
            ['*'],
            now()->addMinutes((int) config($deGestion ? 'sanctum.expiration_gestion' : 'sanctum.expiration')),
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

    /**
     * Distingue la vencida de la revocada o inventada, que dan el mismo 401 con otro código
     * (`sesion_expirada` / `sesion_invalida`). Mira las dos cuentas que mira el guard de Sanctum:
     * el `expires_at` de la fila y la vigencia global contada desde su emisión.
     */
    public function vencio(?string $credencial): bool
    {
        $token = blank($credencial) ? null : PersonalAccessToken::findToken($credencial);

        if ($token === null) {
            return false;
        }

        $global = (int) config('sanctum.expiration');

        return ($token->expires_at !== null && $token->expires_at->isPast())
            || ($global > 0 && $token->created_at->lte(now()->subMinutes($global)));
    }

    /** Revoca todas las credenciales de la persona. */
    public function revocarTodas(User $usuario): void
    {
        $usuario->tokens()->delete();
    }
}
