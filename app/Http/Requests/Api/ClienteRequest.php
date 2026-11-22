<?php

namespace App\Http\Requests\Api;

use App\Support\ReglasFiscales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * El alta y la edición de un cliente (tramo E). Las reglas fiscales son las de
 * `App\Support\ReglasFiscales`, las mismas que exige el formulario de usuarios para una cuenta de
 * tienda. Rechaza, no corrige (M-31): sólo normaliza el formato (documento sin separadores, correo en
 * minúscula). La cuenta de acceso no se asigna desde acá.
 *
 * Métodos:
 *   prepareForValidation()  normaliza el documento y el correo
 *   rules()                 las fiscales más la prohibición de `user_id`
 *   messages() / attributes()  en español y acá (la API no depende de APP_LOCALE)
 */
class ClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige cliente.crear o cliente.editar
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nro_doc' => ReglasFiscales::normalizar($this->input('nro_doc')),
            'email'   => is_string($this->input('email'))
                ? Str::lower(trim($this->input('email')))
                : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        return array_merge(
            ReglasFiscales::para(
                condicionIva: $this->input('condicion_iva'),
                tipoDoc: $this->input('tipo_doc'),
                clienteId: $this->route('cliente')?->id,
            ),
            ['user_id' => ['prohibited']],
        );
    }

    public function messages(): array
    {
        return array_merge(ReglasFiscales::mensajes(), [
            'user_id.prohibited' => 'La cuenta de acceso se administra desde el módulo de personal.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'razon_social'  => 'nombre o razón social',
            'tipo_doc'      => 'tipo de documento',
            'nro_doc'       => 'número de documento',
            'condicion_iva' => 'condición frente al IVA',
            'email'         => 'correo',
            'telefono'      => 'teléfono',
        ];
    }
}
