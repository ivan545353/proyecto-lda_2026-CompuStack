<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de usuarios.
 *
 * Declara el contrato: los filtros que existen son estos y no otros. Es la
 * contracara de A-24, y este módulo es donde el desajuste era peor: el
 * UserController original enviaba `nombres` y el UserDao esperaba `perfil_id`
 * y `estado`, así que el filtro por nombre nunca se aplicó. No había ningún
 * lugar donde estuviera escrito cuál era el correcto.
 *
 * Cada clave de acá tiene un scope con el mismo nombre en el modelo User.
 *
 * `estado` y `situacion` son dos filtros y no uno porque son dos datos:
 * users.activo gobierna el acceso y empleados.fecha_baja el vínculo laboral.
 */
class UsuarioFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige usuario.ver
    }

    public function rules(): array
    {
        return [
            'q'         => ['nullable', 'string', 'max:100'],
            'rol_id'    => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'ambito'    => ['nullable', Rule::in(['gestion', 'tienda'])],
            'estado'    => ['nullable', Rule::in(['activos', 'inactivos'])],
            'situacion' => ['nullable', Rule::in(['en_actividad', 'dados_de_baja'])],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('usuarios.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todas las personas.'
            )
        );
    }
}