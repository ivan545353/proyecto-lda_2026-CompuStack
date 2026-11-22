<?php

namespace App\Http\Requests\Api;

use App\Models\Direccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * El alta y la edición de una dirección de un cliente (tramo E). Rechaza, no corrige (M-31):
 * `es_predeterminada` viaja siempre, porque la API no adivina una casilla ausente. `cliente_id` está
 * PROHIBIDO: el cliente lo determina la ruta, no el cuerpo (C-3), y declararlo prohibido hace que
 * el intento de mudar una dirección se vea en lugar de ignorarse.
 *
 * Métodos:
 *   prepareForValidation()  pone en mayúsculas el código postal (formato, no contenido)
 *   rules()                 las reglas
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class DireccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige cliente.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Un CPA se escribe en mayúsculas (A4400XXX); un largo inválido lo rechaza la regla.
            'codigo_postal' => is_string($this->input('codigo_postal'))
                ? Str::upper(trim($this->input('codigo_postal')))
                : $this->input('codigo_postal'),
        ]);
    }

    public function rules(): array
    {
        return [
            'calle'      => ['required', 'string', 'min:2', 'max:150'],
            'numero'     => ['required', 'string', 'max:10'],
            'piso_depto' => ['nullable', 'string', 'max:20'],

            // Los dos formatos del país: el postal de cuatro dígitos (9011) y el CPA (Z9011XAA).
            'codigo_postal' => ['required', 'string', 'regex:/^(\d{4}|[A-Z]\d{4}[A-Z]{3})$/'],

            'localidad' => ['required', 'string', 'min:2', 'max:100'],
            'provincia' => ['required', Rule::in(Direccion::PROVINCIAS)],

            'es_predeterminada' => ['required', 'boolean'],

            'cliente_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'calle.required'         => 'Escribí la calle.',
            'numero.required'        => 'Escribí la altura.',
            'codigo_postal.required' => 'Escribí el código postal.',
            'codigo_postal.regex'    => 'El código postal va de cuatro dígitos (9011) o en formato CPA (Z9011XAA).',
            'localidad.required'     => 'Escribí la localidad.',
            'provincia.required'     => 'Elegí la provincia.',
            'provincia.in'           => 'Elegí una provincia de la lista.',
            'es_predeterminada.required' => 'Indicá si es la dirección predeterminada.',
            '*.boolean'              => 'Esa opción es sí o no.',
            'cliente_id.prohibited'  => 'La dirección pertenece al cliente de esta pantalla.',
        ];
    }

    public function attributes(): array
    {
        return [
            'calle'             => 'calle',
            'numero'            => 'altura',
            'piso_depto'        => 'piso y departamento',
            'codigo_postal'     => 'código postal',
            'localidad'         => 'localidad',
            'provincia'         => 'provincia',
            'es_predeterminada' => 'dirección predeterminada',
        ];
    }
}
