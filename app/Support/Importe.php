<?php

namespace App\Support;

/**
 * Conversión entre cómo escribe un importe el usuario y cómo lo guarda la base.
 *
 * La regla `numeric` espera "1500.50", y en Argentina se escribe "1.500,50".
 * Interpretarlo mal es caro: leer "1.500" como 1.5 guarda un producto de mil
 * quinientos pesos a un peso con cincuenta, sin ningún error. Es la versión
 * más costosa del hallazgo M-31: aceptar algo distinto de lo que el usuario
 * quiso decir.
 *
 * Por eso normalizar() sólo acepta formas con una única lectura posible. El
 * punto seguido de TRES dígitos es separador de miles; seguido de UNO o DOS,
 * es decimal. Como un precio nunca tiene tres decimales, las dos lecturas no
 * se superponen. Lo que no encaja se devuelve sin tocar, y la regla de
 * validación lo rechaza con su mensaje: nunca se adivina.
 *
 * Métodos:
 *   normalizar()      lo que escribió el usuario → "1500.50"
 *   paraFormulario()  "1500.50" → "1.500,50", para mostrar en un campo
 */
final class Importe
{
    public static function normalizar(mixed $valor): mixed
    {
        // null (campo vacío) o un número que ya viene como número, por
        // ejemplo desde la API de la Etapa 3: no hay nada que interpretar.
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
     *
     * number_format trabaja con float, pero acá sólo se muestra: el valor que
     * se guarda nunca pasa por esta función.
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
}