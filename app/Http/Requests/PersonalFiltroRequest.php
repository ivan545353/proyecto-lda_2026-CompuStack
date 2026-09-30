<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de personal.
 *
 * Declara el contrato: los filtros que existen son estos y no otros. Es la
 * contracara de A-24, y este módulo es donde el desajuste era peor: el
 * UserController original enviaba `nombres` y el UserDao esperaba `perfil_id`
 * y `estado`, así que el filtro por nombre nunca se aplicó.
 *
 * Ya no existe el filtro `ambito`. Esta pantalla lista sólo personal, siempre:
 * separar al cajero de un cliente de la tienda con un filtro opcional no
 * separaba nada, porque había que acordarse de aplicarlo.
 *
 * `estado` y `situacion` son dos filtros y no uno porque son dos datos:
 * users.activo gobierna el acceso y empleados.fecha_baja el vínculo laboral.
 */
class PersonalFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige usuario.ver
    }

    public function rules(): array
    {
        return [
            'q'         => ['nullable', 'string', 'max:100'],
            // Sólo roles de gestión: un rol de tienda no tiene a nadie que
            // listar en esta pantalla.
            'rol_id'    => ['nullable', 'integer', Rule::exists('roles', 'id')->where('ambito', 'gestion')],
            'estado'    => ['nullable', Rule::in(['activos', 'inactivos'])],
            'situacion' => ['nullable', Rule::in(['en_actividad', 'dados_de_baja'])],
            'page'      => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('personal.index')->with(
                'error',
                'Ese filtro no es válido. Se muestra todo el personal.'
            )
        );
    }
}