<?php

namespace App\Impresion;

use App\Support\Importe;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Una columna que un listado sabe imprimir: su rótulo, de dónde sale el valor y qué permiso pide.
 */
final class Columna
{
    public const TEXTO      = 'texto';
    public const ENTERO     = 'entero';
    public const IMPORTE    = 'importe';
    public const PORCENTAJE = 'porcentaje';

    /** Con qué empieza una celda que una planilla ejecuta como fórmula. */
    private const INICIO_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  Closure(Model): (string|int|float|null)  $valor
     * @param  string|null  $suma  expresión que la base suma para el total y los subtotales
     */
    public function __construct(
        public readonly string $clave,
        public readonly string $rotulo,
        public readonly Closure $valor,
        public readonly string $tipo = self::TEXTO,
        public readonly bool $porOmision = true,
        public readonly ?string $permiso = null,
        public readonly ?string $suma = null,
    ) {
    }

    /** Los números van a la derecha en el papel. */
    public function aLaDerecha(): bool
    {
        return $this->tipo !== self::TEXTO;
    }

    public function valorDe(Model $fila): mixed
    {
        return ($this->valor)($fila);
    }

    public function paraPdf(Model $fila): string
    {
        return $this->enPdf($this->valorDe($fila));
    }

    public function paraCsv(Model $fila): string
    {
        return $this->enCsv($this->valorDe($fila));
    }

    /** Un valor que no hay se imprime como una raya, nunca como un cero. */
    public function enPdf(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($this->tipo) {
            self::IMPORTE    => Importe::pesos($valor),
            self::PORCENTAJE => Importe::numero($valor, 2).' %',
            self::ENTERO     => Importe::numero($valor, 0),
            default          => (string) $valor,
        };
    }

    /** Números con coma y sin separador de miles, para que la planilla los lea como números. */
    public function enCsv(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($this->tipo) {
            self::IMPORTE, self::PORCENTAJE => number_format((float) $valor, 2, ',', ''),
            self::ENTERO                    => (string) (int) $valor,
            default                         => self::neutralizar((string) $valor),
        };
    }

    /** Un texto cargado por un usuario que empieza como fórmula se escribe con un apóstrofo adelante. */
    private static function neutralizar(string $texto): string
    {
        return in_array(mb_substr($texto, 0, 1), self::INICIO_DE_FORMULA, true) ? "'".$texto : $texto;
    }
}