<?php

namespace App\Impresion;

use App\Models\User;
use App\Support\Importe;

/**
 * Lo que un módulo declara para imprimirse; el motor compartido es ImpresionService.
 */
abstract class Listado
{
    /** El título del papel: «Catálogo de productos». */
    abstract public function titulo(): string;

    /** El comienzo del nombre del archivo: «catalogo». */
    abstract public function archivo(): string;

    /** @return array<int, Columna> en el orden en que se imprimen */
    abstract public function columnas(): array;

    /**
     * Los filtros en palabras del negocio, para el bloque «Alcance».
     *
     * @param  array<string, mixed>  $filtros  los del Form Request de filtros del módulo
     * @return array<string, string>
     */
    abstract public function filtrosAplicados(array $filtros): array;

    /** @return array{0: string, 1: string} el singular y el plural de lo que se lista */
    abstract protected function sustantivo(): array;

    /** Las relaciones que las columnas leen, cargadas una vez por tanda. */
    public function relaciones(): array
    {
        return [];
    }

    /** Lo que las columnas cuentan, como `withCount`: una subconsulta en el SELECT, no una consulta por fila. */
    public function conteos(): array
    {
        return [];
    }

    /** @return array<string, Agrupacion> los cortes que el papel ofrece, por su clave */
    public function agrupaciones(): array
    {
        return [];
    }

    
    public function orientacion(): string
    {
        return 'vertical';
    }

    /** La columna única por la que el CSV avanza de a tandas, y que da su orden. */
    public function columnaDeTanda(): string
    {
        return 'id';
    }

    public function tandaDescendente(): bool
    {
        return false;
    }

    /**
     * Las columnas que ese usuario puede pedir: sin el permiso, la columna no existe para él.
     *
     * @return array<int, Columna>
     */
    public function columnasPara(User $usuario): array
    {
        return array_values(array_filter(
            $this->columnas(),
            fn (Columna $columna) => $columna->permiso === null || $usuario->can($columna->permiso),
        ));
    }

    public function columna(string $clave): ?Columna
    {
        foreach ($this->columnas() as $columna) {
            if ($columna->clave === $clave) {
                return $columna;
            }
        }

        return null;
    }

    /** «1 producto», «20.012 productos». */
    public function cuenta(int $cantidad): string
    {
        [$uno, $varios] = $this->sustantivo();

        return Importe::numero($cantidad, 0).' '.($cantidad === 1 ? $uno : $varios);
    }
}