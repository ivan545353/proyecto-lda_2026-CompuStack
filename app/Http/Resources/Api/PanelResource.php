<?php

namespace App\Http\Resources\Api;

use App\Support\Comparacion;
use App\Support\Importe;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo común a los dos paneles por la API (tramo H): el período, el anterior con el que se compara y la
 * comparación de cada número. La frase de la comparación la arma el servidor con `Comparacion`, la misma
 * de la web: la regla de cuándo hay porcentaje (invariante 16) no se duplica en el cliente.
 *
 * @property array $resource las métricas de `PanelService`
 */
abstract class PanelResource extends JsonResource
{
    public function __construct(array $metricas, protected CarbonInterface $desde, protected CarbonInterface $hasta)
    {
        parent::__construct($metricas);
    }

    /** Las fechas del filtro y su texto de pantalla. */
    protected function periodo(): array
    {
        return [
            'desde' => $this->desde->toDateString(),
            'hasta' => $this->hasta->toDateString(),
            'texto' => "Del {$this->desde->format('d/m/Y')} al {$this->hasta->format('d/m/Y')}",
        ];
    }

    /** El período anterior: sus fechas y la frase «entre el … y el …» que usa cada comparación. */
    protected function anterior(array $numeros): array
    {
        $anterior = $this->resource['anterior'];

        return [
            'desde' => $anterior['desde']->toDateString(),
            'hasta' => $anterior['hasta']->toDateString(),
            'texto' => $this->contra(),
            ...$numeros,
        ];
    }

    /** Un número contra el del período anterior; `$pesos` dice si el de antes se escribe en pesos. */
    protected function comparar(float $actual, float $anterior, bool $pesos): array
    {
        $comparacion = Comparacion::entre($actual, $anterior);

        return [
            'sentido'    => $comparacion->sentido,
            'porcentaje' => $comparacion->porcentaje,
            'texto'      => $comparacion->texto(
                $this->contra(),
                $pesos ? Importe::pesos($anterior) : (string) (int) $anterior,
            ),
        ];
    }

    private function contra(): string
    {
        $anterior = $this->resource['anterior'];

        return "entre el {$anterior['desde']->format('d/m')} y el {$anterior['hasta']->format('d/m')}";
    }
}
