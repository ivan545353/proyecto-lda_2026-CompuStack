<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El período del panel por la API (tramo H): las mismas reglas que el de la web. Un período inválido es
 * un 422 con el error en su campo (patrón HL-120), no una redirección al mes en curso.
 */
class PanelFiltroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // el permiso lo exige la ruta
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    // Por omisión, del primero del mes a hoy.
    public function desde(): CarbonInterface
    {
        $desde = $this->validated()['desde'] ?? null;

        return $desde ? Carbon::parse($desde)->startOfDay() : now()->startOfMonth();
    }

    public function hasta(): CarbonInterface
    {
        $hasta = $this->validated()['hasta'] ?? null;

        return $hasta ? Carbon::parse($hasta)->endOfDay() : now()->endOfDay();
    }

    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }

    public function attributes(): array
    {
        return [
            'desde' => 'fecha inicial',
            'hasta' => 'fecha final',
        ];
    }
}
