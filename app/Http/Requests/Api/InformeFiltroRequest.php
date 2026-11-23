<?php

namespace App\Http\Requests\Api;

use App\Services\InformeVentasService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Los dos informes de ventas por la API (tramo H): el período del panel más el paginado de todo listado.
 * Un período inválido es un 422 en su campo (HL-120). Por omisión pagina de a 15, como la pantalla.
 */
class InformeFiltroRequest extends PaginadoRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }

    public function attributes(): array
    {
        return ['desde' => 'fecha inicial', 'hasta' => 'fecha final'];
    }

    public function porPagina(): int
    {
        return $this->integer('por_pagina', InformeVentasService::POR_PAGINA);
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

    /** El período que se usó, con su texto, y si lo eligió quien pregunta (el vacío dice cosas distintas). */
    public function criterios(): array
    {
        return [
            'desde'   => $this->desde()->toDateString(),
            'hasta'   => $this->hasta()->toDateString(),
            'texto'   => "Del {$this->desde()->format('d/m/Y')} al {$this->hasta()->format('d/m/Y')}",
            'elegido' => $this->hasAny(['desde', 'hasta']),
        ];
    }
}
