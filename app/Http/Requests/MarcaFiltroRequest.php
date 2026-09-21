<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de marcas.
 *
 * Los parámetros de la URL son entrada del usuario igual que un formulario, y
 * la regla del proyecto es que la entrada se valida en un Form Request. Además
 * documenta el contrato: los filtros que existen son estos y no otros.
 *
 * Es la contracara del hallazgo A-24. El ItemController original mandaba
 * `categoriaId` y el ItemDao leía `categoria`: no había ningún lugar donde
 * estuviera escrito qué filtros acepta el listado, así que el desajuste no
 * tenía dónde detectarse. Acá el juego de filtros está declarado, y el scope
 * del modelo lleva el mismo nombre que el parámetro.
 *
 * Un filtro inválido no rompe la pantalla ni devuelve un listado distinto al
 * pedido: redirige al listado limpio y lo avisa.
 */
class MarcaFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige marca.ver
    }

    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activas', 'inactivas'])],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }


    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('marcas.index')->with(
                'error',
                'La dirección tenía un filtro que no existe. Se muestra el listado completo.'
            )
        );
    }
}