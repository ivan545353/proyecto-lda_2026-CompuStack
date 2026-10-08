<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Valida los filtros del listado de proveedores.
 *
 * Los parámetros de la URL son entrada del usuario igual que un formulario, así
 * que se validan en un Form Request. Además dejan escrito el contrato: los
 * filtros que existen son estos y no otros, con el mismo nombre que el scope del
 * modelo.
 *
 *
 * Un filtro inválido redirige al listado limpio con un aviso, en vez de romper
 * la pantalla o devolver un resultado distinto al pedido en silencio.
 */
class ProveedorFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige proveedor.ver
    }

    public function rules(): array
    {
        return [
            'q'      => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos'])],

            // La misma constante que usa el scope y que arma el selector de la
            // vista: los tres comparan exactamente los mismos valores.
            'canal'  => ['nullable', Rule::in(array_keys(Proveedor::CANALES))],

            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('proveedores.index')->with(
                'error',
                'Ese filtro no es válido. Se muestran todos los proveedores.'
            )
        );
    }
}