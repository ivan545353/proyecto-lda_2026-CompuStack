<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
/**
 * Validación del formulario de rol.
 *
 * Sirve para el alta y para la edición. Dos reglas condicionales:
 *
 *   - nombre y ámbito no son obligatorios si el rol es de sistema, porque esos
 *     campos están deshabilitados en el formulario y no viajan
 *   - los permisos son obligatorios sólo para roles de ámbito gestión: uno sin
 *     permisos entra al sistema y recibe 403 en cada pantalla. Los de tienda sí
 *     pueden no tener ninguno, y el rol Cliente es exactamente ese caso
 *
 * Métodos:
 *   authorize()        true; la ruta ya exige el permiso rol.editar
 *   rules()            reglas, con las condicionales
 *   messages()         mensajes en español
 *   ambitoEfectivo()   el ámbito real, porque el de un rol de sistema no viaja
 */
class RolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta ya exige el permiso rol.editar
    }

    public function rules(): array
    {
        $rol = $this->route('rol');

        return [
            'nombre' => [
                Rule::requiredIf(fn () => ! $rol?->es_sistema),
                'string', 'max:50',
                Rule::unique('roles', 'nombre')->ignore($rol?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'ambito'      => [
                Rule::requiredIf(fn () => ! $rol?->es_sistema),
                Rule::in(['gestion', 'tienda']),
            ],
            // Un rol de gestión sin permisos no puede hacer nada: entra al
            // sistema y recibe 403 en cada pantalla. Los de tienda sí pueden
            // no tener ninguno; el rol Cliente es exactamente ese caso.
            'permisos' => [
                Rule::requiredIf(fn () => $this->ambitoEfectivo() === 'gestion'),
                'array',
            ],
            'permisos.*' => ['integer', 'exists:permisos,id'],
        ];
    }

    /** El ámbito de los roles de sistema no viaja en el formulario: está deshabilitado. */
    private function ambitoEfectivo(): string
    {
        $rol = $this->route('rol');

        return $rol?->es_sistema
            ? $rol->ambito
            : (string) $this->input('ambito', 'gestion');
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El rol necesita un nombre.',
            'nombre.unique'   => 'Ya existe un rol con ese nombre.',
            'ambito.in'       => 'El ámbito debe ser gestión o tienda.',
            'permisos.*.exists' => 'Se envió un permiso que no existe.',
            'permisos.required' => 'Un rol de gestión necesita al menos un permiso.',
        ];
    }
}