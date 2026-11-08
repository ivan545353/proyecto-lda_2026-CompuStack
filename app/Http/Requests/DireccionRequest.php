<?php

namespace App\Http\Requests;

use App\Models\Direccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validación del formulario de dirección. Sirve para el alta y la edición.
 *
 * `cliente_id` está PROHIBIDO: el cliente lo determina la ruta, no el cuerpo de
 * la petición. Si se leyera del cuerpo, alguien podría mover una dirección a
 * otro cliente enviando un id distinto. El servicio igual enumera los campos y
 * nunca lo escribiría, pero declararlo prohibido hace que el intento se vea en
 * vez de ignorarse: mismo criterio con el que se cerró C-3.
 *
 * Métodos:
 *   authorize()             true; la ruta exige cliente.editar
 *   prepareForValidation()  normaliza el checkbox y el código postal
 *   rules()                 las reglas
 *   messages() / attributes()
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
            // El checkbox sin marcar no viaja en el POST. Sin esto, no habría
            // forma de distinguir "no la quiero predeterminada" de "no mandé el
            // campo".
            'es_predeterminada' => $this->boolean('es_predeterminada'),

            // Un CPA se escribe en mayúsculas (A4400XXX). Normaliza el formato,
            // no el contenido: si tiene un largo inválido, la regla lo rechaza.
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

            // Acepta los dos formatos que se usan en el país: el postal de
            // cuatro dígitos (9011) y el CPA de ocho (Z9011XAA). Rechazar el
            // viejo dejaría afuera a quien copia la dirección de una factura.
            'codigo_postal' => ['required', 'string', 'regex:/^(\d{4}|[A-Z]\d{4}[A-Z]{3})$/'],

            'localidad' => ['required', 'string', 'min:2', 'max:100'],
            'provincia' => ['required', Rule::in(Direccion::PROVINCIAS)],

            'es_predeterminada' => ['required', 'boolean'],

            // El cliente lo determina la ruta, no el cuerpo de la petición.
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