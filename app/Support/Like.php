<?php

namespace App\Support;

/**
 * Patrón para búsquedas "contiene" con LIKE.
 *
 * El binding de PDO protege de la inyección SQL, pero no del significado de
 * los comodines: quien busca "100%" espera encontrar ese texto, no la tabla
 * entera. Se escapan %, _ y la barra invertida, que es el carácter de escape.
 *
 * Existe como clase porque la misma regla estaba copiada en tres modelos.
 */
final class Like
{
    public static function contiene(string $texto): string
    {
        return '%'.addcslashes(trim($texto), '%_\\').'%';
    }
}