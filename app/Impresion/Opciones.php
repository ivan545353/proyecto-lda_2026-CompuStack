<?php

namespace App\Impresion;

/**
 * Lo que la persona eligió en el diálogo, ya validado contra el listado y sus permisos.
 */
final class Opciones
{
    public const FORMATOS = [
        'pdf' => 'PDF para imprimir',
        'csv' => 'Planilla (CSV), completa',
    ];

    public const ORIENTACIONES = [
        'vertical'   => 'Vertical',
        'horizontal' => 'Horizontal',
    ];

    public const HOJAS = [
        'a4'     => 'A4',
        'oficio' => 'Oficio (216 × 356 mm)',
    ];

    public const DENSIDADES = [
        'normal'   => 'Normal',
        'compacta' => 'Compacta',
    ];

    /**
     * @param  array<int, Columna>  $columnas  en el orden del listado
     */
    public function __construct(
        public readonly string $formato,
        public readonly array $columnas,
        public readonly string $orientacion,
        public readonly string $hoja,
        public readonly string $densidad,
        public readonly ?Agrupacion $agrupacion = null,
        public readonly bool $saltoDeHoja = false,
    ) {
    }

    public function esCsv(): bool
    {
        return $this->formato === 'csv';
    }

    public function compacta(): bool
    {
        return $this->densidad === 'compacta';
    }

    /** El tamaño de hoja con el nombre que entiende dompdf. */
    public function papelDompdf(): string
    {
        return $this->hoja === 'oficio' ? 'legal' : 'a4';
    }

    public function orientacionDompdf(): string
    {
        return $this->orientacion === 'horizontal' ? 'landscape' : 'portrait';
    }

    /** @return array<int, string> */
    public function claves(): array
    {
        return array_map(fn (Columna $columna) => $columna->clave, $this->columnas);
    }
}