<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\UsuarioService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el cambio de rol de una persona.
 *
 * Form Request propio porque la acción es propia: ruta propia, permiso propio
 * (`usuario.cambiar_rol`) y pantalla propia. Que el rol no sea un campo del
 * formulario de edición es la mitad de la corrección de C-3; esta clase es la
 * otra mitad.
 *
 * Prohíbe que alguien se cambie el rol a sí mismo. No es paranoia: es el
 * escenario exacto del hallazgo. Un usuario con permiso de edición se asignaba
 * el perfil Administrador porque el controlador armaba el DTO desde el body y
 * nunca comparaba el id del body contra el del token.
 *
 * La regla vive acá y no en el servicio porque depende de QUIÉN está pidiendo,
 * y el servicio no conoce la sesión. La API de la Etapa 3 reutiliza este mismo
 * Form Request.
 */
class CambiarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->usuario()->esDeGestion(), 404);

        return true;   // la ruta exige usuario.cambiar_rol
    }

    private function usuario(): User
    {
        return $this->route('usuario');
    }

    public function rules(): array
    {
        $usuario = $this->usuario();

        // Los roles posibles los define el servicio: acá se consultan, no se
        // vuelven a decidir.
        $asignables = app(UsuarioService::class)
            ->rolesAsignablesA($usuario)
            ->pluck('id')
            ->all();

        return [
            'rol_id' => [
                // `bail` para que el usuario lea un motivo y no cuatro.
                'bail', 'required', 'integer',
                Rule::prohibitedIf(fn () => $usuario->is($this->user())),
                Rule::exists('roles', 'id'),
                Rule::notIn([$usuario->rol_id]),
                Rule::in($asignables),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'rol_id.required'   => 'Elegí el rol que le corresponde.',
            'rol_id.prohibited' => 'No podés cambiar tu propio rol. Pedíselo a otra persona con ese permiso.',
            'rol_id.exists'     => 'Ese rol no existe.',
            'rol_id.not_in'     => 'Ya tiene ese rol: no hay nada que cambiar.',
            'rol_id.in'         => 'Ese rol es de otro tipo de persona. Sólo podés asignar roles equivalentes al actual.',
        ];
    }

    public function attributes(): array
    {
        return ['rol_id' => 'rol'];
    }
}