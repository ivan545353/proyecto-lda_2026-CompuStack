<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Genera un slug único para un modelo.
 *
 * El slug no es un campo del formulario: se deriva del nombre. Un campo menos
 * que el usuario puede escribir mal, y un campo menos que validar.
 *
 * Resuelve el choque de reglas que tiene el esquema en `categorias`: el nombre
 * es único por padre —dos ramas pueden tener una hija "SSD"— pero la columna
 * `slug` es UNIQUE global. Sin el sufijo, la segunda "SSD" reventaría con un
 * error 23000 del driver, que el usuario vería como pantalla de error en vez
 * de como mensaje. Acá se resuelve antes de llegar a la base.
 *
 * El índice UNIQUE sigue siendo la garantía real: dos altas simultáneas con el
 * mismo nombre podrían calcular el mismo sufijo antes de que ninguna inserte.
 * Es una carrera que requiere dos administradores creando la misma marca en el
 * mismo instante; si alguna vez importa, se envuelve en un reintento. La base
 * no permite el duplicado en ningún caso.
 */
class Slug
{
    /** Longitud de la columna (120) menos margen para el sufijo. */
    private const LARGO_BASE = 110;

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelo
     * @param  int|null  $ignorarId  el propio id, al editar
     */
    public static function unicoPara(string $modelo, string $texto, ?int $ignorarId = null): string
    {
        $base = rtrim(Str::limit(Str::slug($texto), self::LARGO_BASE, ''), '-');

        // Un nombre íntegramente en caracteres que Str::slug descarta (por
        // ejemplo "???") dejaría el slug vacío y todas esas filas chocarían
        // entre sí. El nombre ya pasó por la validación; esto sólo evita que el
        // caso raro termine en un error de base.
        if ($base === '') {
            $base = 'sin-nombre';
        }

        $slug   = $base;
        $sufijo = 1;

        while (self::existe($modelo, $slug, $ignorarId)) {
            $slug = $base.'-'.(++$sufijo);
        }

        return $slug;
    }

    private static function existe(string $modelo, string $slug, ?int $ignorarId): bool
    {
        return $modelo::query()
            ->where('slug', $slug)
            ->when($ignorarId, fn ($query) => $query->whereKeyNot($ignorarId))
            ->exists();
    }
}