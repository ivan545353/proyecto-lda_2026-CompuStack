<?php

namespace App\Http\Requests;

use App\Models\Cliente;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de clientes.
 *
 * Declara el contrato: los filtros que existen son estos y no otros. Es la
 * corrección de fondo de A-24 — el bug existió porque no había ningún lugar
 * donde constara qué filtros acepta un listado.
 *
 * Cada clave de acá tiene un scope con el mismo nombre en el modelo Cliente, y
 * la lista de condiciones sale de la constante del modelo, así que el filtro y
 * el selector de la vista comparan exactamente los mismos valores.
 */
class ClienteFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige cliente.ver
    }

    public function rules(): array
    {
        return [
            'q'             => ['nullable', 'string', 'max:100'],
            'condicion_iva' => ['nullable', Rule::in(array_keys(Cliente::CONDICIONES_IVA))],
            'cuenta'        => ['nullable', Rule::in(['con_cuenta', 'sin_cuenta'])],
            'page'          => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('clientes.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todos los clientes.'
            )
        );
    }
}