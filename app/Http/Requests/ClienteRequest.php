<?php

namespace App\Http\Requests;

use App\Support\ReglasFiscales;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Validación del formulario de cliente. Sirve para el alta y para la edición.
 *
 * Las reglas fiscales son las mismas que exige el formulario de usuarios
 * cuando el rol es de ámbito tienda, así que viven en App\Support\ReglasFiscales
 * y se piden con el prefijo vacío.
 *
 * Métodos:
 *   authorize()             true; el permiso ya lo exige la ruta
 *   prepareForValidation()  normaliza el documento y el correo
 *   rules()                 las fiscales más la prohibición
 *   messages() / attributes()
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