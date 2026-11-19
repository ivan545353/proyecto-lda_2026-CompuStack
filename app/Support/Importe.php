<?php

namespace App\Support;

/**
 * Conversión entre cómo escribe un importe el usuario y cómo lo guarda la base.
 *
 * Métodos:
 *   normalizar()      lo que escribió el usuario → "1500.50"
 *   paraFormulario()  "1500.50" → "1.500,50", para mostrar en un campo
 *   json()            1500.5 → "1500.50", la forma de un importe en la API
 */
final class Importe
{
    public static function normalizar(mixed $valor): mixed
    {
        // null (campo vacío) o un número que ya viene como número
        if (! is_string($valor)) {
            return $valor;
        }

        $limpio = str_replace(['$', ' '], '', $valor);

        return match (true) {
            // 1500 · 1500,5 · 1500,50 — coma decimal
            (bool) preg_match('/^\d+(,\d{1,2})?$/', $limpio)
                => str_replace(',', '.', $limpio),

            // 1.500 · 1.500,50 · 12.345.678,90 — punto de miles, coma decimal
            (bool) preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $limpio)
                => str_replace(['.', ','], ['', '.'], $limpio),

            // 1500.5 · 1500.50 — punto decimal, como en una calculadora
            (bool) preg_match('/^\d+\.\d{1,2}$/', $limpio)
                => $limpio,

            default => $valor,
        };
    }

    /**
     * Para el value de un campo. Si lo que llega no es un número (lo que el
     * usuario escribió y no validó), se muestra tal cual para que lo corrija.
     */
    public static function paraFormulario(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return is_numeric($valor)
            ? number_format((float) $valor, 2, ',', '.')
            : (string) $valor;
    }

    /**
     * Un número con coma decimal y sin ceros sobrantes: 2,500 → "2,5".
     *
     * Para porcentajes y multiplicadores, que no son importes: un precio
     * siempre lleva dos decimales, una ganancia de 2,5 escrita "2,500" se lee
     * peor, y un flete de 2,375 % necesita los tres.
     */
    public static function numero(mixed $valor, int $decimales = 3): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        if (! is_numeric($valor)) {
            return (string) $valor;
        }

        $texto = number_format((float) $valor, $decimales, ',', '.');

        return str_contains($texto, ',') ? rtrim(rtrim($texto, '0'), ',') : $texto;
    }

    /**
     * Un importe tal como viaja en la API: cadena, dos decimales, punto, sin miles.
     *
     * Cadena y no número porque un número JSON es `double` en Dart, y `decimal(12,2)`
     * (A-17) no tiene que pasar por punto flotante en el camino. La app sólo formatea.
     * Como paraFormulario(), trabaja con float sólo para mostrar: no es el valor que
     * se guarda ni el que se calcula.
     */
    public static function json(int|float|string $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /** "1500.5" → "$ 1.500,50", para mostrar un importe en pantalla. */
    public static function pesos(mixed $valor): string
    {
        return '$ '.self::paraFormulario($valor ?? 0);
    }
}