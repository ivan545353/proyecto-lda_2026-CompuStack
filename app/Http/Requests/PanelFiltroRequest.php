<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// El contrato de filtros del panel: el período y nada más (A-24). Sin filtro por
// canal, que en la Etapa 1 tendría un solo valor posible.
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

    // Un período inválido vuelve al panel limpio con un aviso, igual que los demás
    // listados. Redirige a la URL sin query para servir a las dos rutas del panel.
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect($this->url())->with(
                'error',
                'Ese período no es válido. Se muestra el mes en curso.'
            )
        );
    }
}