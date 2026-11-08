<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de una recepción de mercadería.
 */
class RecepcionCompraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // la ruta exige compra.recibir
    }

    public function rules(): array
    {
        return [
            'cantidades'   => ['required', 'array', 'min:1'],
            'cantidades.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'remito_numero' => ['nullable', 'string', 'max:30', 'required_with:remito_fecha'],
            'remito_fecha'  => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'required_with:remito_numero'],
        ];
    }

    public function messages(): array
    {
        return [
            'cantidades.required' => 'No llegó ninguna cantidad para registrar.',
            'cantidades.*.integer' => 'Las unidades recibidas se escriben en números enteros.',
            'cantidades.*.min'     => 'Las unidades recibidas no pueden ser negativas.',
            'cantidades.*.max'     => 'Esa cantidad parece un error de tipeo.',
            'remito_numero.required_with' => 'Si cargás la fecha del remito, cargá también su número.',
            'remito_numero.max'           => 'El número de remito tiene hasta 30 caracteres.',
            'remito_fecha.required_with'  => 'Si cargás el número del remito, cargá también su fecha.',
            'remito_fecha.date_format'    => 'La fecha del remito no es una fecha válida.',
            'remito_fecha.before_or_equal' => 'La fecha del remito no puede ser posterior a hoy.',
        ];
    }

    public function attributes(): array
    {
        return [
            'remito_numero' => 'número de remito',
            'remito_fecha'  => 'fecha del remito',
        ];
    }
}