<?php

namespace App\Support;

use App\Exceptions\ReglaDeNegocioException;

/**
 * El cálculo del precio de venta a partir del costo neto, en las tres etapas
 * del plan: se forma el neto, se le aplican los impuestos, y el desglose se
 * deriva hacia atrás del precio final ya redondeado.
 *
 * No conoce la base: recibe los factores y los impuestos ya resueltos, así que
 * el simulador, el recálculo de un producto y el ajuste masivo del tramo D
 * comparten el cálculo en vez de tener cada uno el suyo.
 *
 * Métodos:
 *   calcular()  el precio de contado y el de lista, cada uno con su desglose
 */
final class MotorPrecios
{
    /**
     * @param  array<int, array{nombre: string, modo: string, porcentaje: float|string}>  $factores
     * @param  array<int, array{nombre: string, alicuota: float|string, comprobante: string}>  $impuestos
     */
    public function __construct(
        private array $factores = [],
        private array $impuestos = [],
        private float|string $recargoLista = 0,
        private float|string $redondeoMultiplo = 0.01,
        private string $redondeoModo = 'cercano',
    ) {
    }

    /**
     * @return array{
     *     costo_neto: float, ganancia: float, margen: float,
     *     factores: array<int, array{nombre: string, modo: string, porcentaje: float, importe: float}>,
     *     contado: array{neto: float, final: float, impuestos: array<int, array{nombre: string, alicuota: float, comprobante: string, importe: float}>},
     *     lista: array{neto: float, final: float, impuestos: array<int, array{nombre: string, alicuota: float, comprobante: string, importe: float}>}
     * }
     */
    public function calcular(float|string $costoNeto, float|string $ganancia): array
    {
        $costo         = (float) $costoNeto;
        $multiplicador = (float) $ganancia;

        [$netoContado, $factores] = $this->aplicarFactores($costo * $multiplicador);

        return [
            'costo_neto' => round($costo, 2),
            'ganancia'   => $multiplicador,
            'margen'     => $this->margen($costo, $netoContado),
            'factores'   => $factores,
            'contado'    => $this->desglosar($netoContado),
            'lista'      => $this->desglosar($netoContado * (1 + ((float) $this->recargoLista / 100))),
        ];
    }

    /** Etapa 1: los factores que forman el neto, en orden, cada uno con lo que sumó. */
    private function aplicarFactores(float $neto): array
    {
        $aplicados = [];

        foreach ($this->factores as $factor) {
            $porcentaje = (float) $factor['porcentaje'];
            $anterior   = $neto;

            $neto = $factor['modo'] === 'recupero'
                ? $this->recuperar($neto, $porcentaje, $factor['nombre'])
                : $neto * (1 + ($porcentaje / 100));

            $aplicados[] = [
                'nombre'     => $factor['nombre'],
                'modo'       => $factor['modo'],
                'porcentaje' => $porcentaje,
                'importe'    => round($neto - $anterior, 2),
            ];
        }

        return [$neto, $aplicados];
    }

    /** Lo que se calcula sobre la venta se recupera dividiendo: con 3 %, multiplicar por 1,03 recupera 2,91 %. */
    private function recuperar(float $neto, float $porcentaje, string $nombre): float
    {
        if ($porcentaje >= 100) {
            throw new ReglaDeNegocioException(
                "«{$nombre}» recupera {$porcentaje} % sobre la venta: con 100 % o más no hay precio que lo cubra."
            );
        }

        return $neto / (1 - ($porcentaje / 100));
    }

     private function desglosar(float $neto): array
    {
        return $this->descomponer($this->redondear($neto * $this->coeficiente()));
    }

    /**
     * Descompone un importe final en su neto y sus impuestos.
     *
     * Es la etapa 3 aplicada a un importe que ya existe. La línea de venta la usa
     * para congelar su desglose con la misma cuenta con la que se formó el precio,
     * en lugar de una parecida: dos copias de una división con redondeo son dos
     * oportunidades de que una dé un centavo distinto.
     *
     * @return array{neto: float, final: float, impuestos: array<int, array{nombre: string, alicuota: float, comprobante: string, importe: float}>}
     */
    public function descomponer(float|string $total): array
    {
        $final     = (float) $total;
        $netoFinal = round($final / $this->coeficiente(), 2);

        return [
            'neto'      => $netoFinal,
            'final'     => $final,
            'impuestos' => $this->repartir($netoFinal, $final),
        ];
    }

    /** Cuánto multiplica al neto el conjunto de impuestos. */
    private function coeficiente(): float
    {
        $coeficiente = 1.0;

        foreach ($this->impuestos as $impuesto) {
            $coeficiente *= 1 + ((float) $impuesto['alicuota'] / 100);
        }

        return $coeficiente;
    }

    /**
     * Reparte la diferencia entre el neto y el final, impuesto por impuesto.
     *
     * El último se lleva lo que queda en vez de calcularse. Así la suma del
     * desglose da el precio final al centavo, que es lo que mira la Ley 27.743.
     */
    private function repartir(float $netoFinal, float $final): array
    {
        $ultimo     = count($this->impuestos) - 1;
        $base       = $netoFinal;
        $repartidos = [];

        foreach (array_values($this->impuestos) as $posicion => $impuesto) {
            $alicuota = (float) $impuesto['alicuota'];

            $importe = $posicion === $ultimo
                ? round($final - $base, 2)
                : round($base * $alicuota / 100, 2);

            $repartidos[] = [
                'nombre'      => $impuesto['nombre'],
                'alicuota'    => $alicuota,
                'comprobante' => $impuesto['comprobante'],
                'importe'     => $importe,
            ];

            $base = round($base + $importe, 2);
        }

        return $repartidos;
    }

    /** Al múltiplo y con el modo que fija la política. */
    public function redondear(float $valor): float
    {
        $multiplo = (float) $this->redondeoMultiplo;

        if ($multiplo <= 0) {
            return round($valor, 2);
        }

        $pasos = round($valor / $multiplo, 9);

        $pasos = match ($this->redondeoModo) {
            'arriba' => ceil($pasos),
            'abajo'  => floor($pasos),
            default  => round($pasos),
        };

        return round($pasos * $multiplo, 2);
    }

    /** El margen sobre la venta que equivale al multiplicador, con los factores ya adentro. */
    private function margen(float $costo, float $neto): float
    {
        return $neto > 0 ? round(($neto - $costo) / $neto * 100, 2) : 0.0;
    }
}