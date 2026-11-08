<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Valida los parámetros del buscador remoto.
 *
 * Métodos:
 *   prepareForValidation()  recorta los espacios del texto
 *   rules()                 las reglas
 *   failedValidation()      422 en JSON, no una redirección
 */
class BusquedaRequest extends FormRequest
{
    /**
     * Cuántos caracteres hacen falta para buscar.
     */
    public const MINIMO = 2;

    public function authorize(): bool
    {
        return true;   // cada ruta exige el permiso de ver su entidad
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => trim((string) $this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:'.self::MINIMO, 'max:60'],
            'estado' => ['nullable', Rule::in(['activos', 'inactivos', 'todos'])],
            'proveedor_id'  => ['nullable', 'integer', 'exists:proveedores,id'],
            'con_proveedor' => ['nullable', 'boolean'],
            'excluir'   => ['nullable', 'array', 'max:50'],
            'excluir.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Escribí qué buscás.',
            'q.min'      => 'Escribí al menos :min caracteres.',
            'excluir.max' => 'No se pueden excluir más de :max productos.',
        ];
    }

    public function attributes(): array
    {
        return [
            'q'             => 'texto a buscar',
            'proveedor_id'  => 'proveedor',
            'con_proveedor' => 'con proveedor',
            'estado'        => 'estado',
            'excluir'       => 'productos ya cargados',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => $validator->errors()->first(),
        ], 422));
    }
}