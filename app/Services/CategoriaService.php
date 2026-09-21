<?php

namespace App\Services;

use App\Models\Categoria;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reglas de negocio de categorías.
 *
 * Mismo patrón que MarcaService: recibe datos ya validados, no conoce la
 * petición ni la sesión, y la API de la Etapa 3 lo va a usar sin cambios.
 *
 * Reglas propias:
 *
 *   1. El slug se calcula acá, con sufijo si ya existe. Es lo que permite dos
 *      "SSD" en ramas distintas: el nombre es único por padre, pero la columna
 *      slug es UNIQUE global.
 *   2. Una categoría con subcategorías o con productos NO se borra: se
 *      desactiva. Borrarla fallaría por la clave foránea de productos, o peor,
 *      las hijas quedarían convertidas en raíces sin que nadie lo pidiera
 *      (parent_id tiene nullOnDelete).
 *   3. Desactivar no se propaga a las hijas. Desactivar "Componentes" no
 *      apaga en silencio veinte subcategorías: si hace falta, se hace una por
 *      una y queda a la vista.
 *
 * Métodos:
 *   crear()       alta
 *   actualizar()  edición; no toca peso_default_gramos
 *   eliminar()    baja física o lógica; devuelve cuál fue
 */
class CategoriaService
{
    public function crear(array $datos): Categoria
    {
        return DB::transaction(fn () => Categoria::create([
            'parent_id' => $datos['parent_id'] ?? null,
            'nombre'    => $datos['nombre'],
            'slug'      => Slug::unicoPara(Categoria::class, $datos['nombre']),
            'orden'     => $datos['orden'],
            'activo'    => $datos['activo'],
        ]));
    }

    public function actualizar(Categoria $categoria, array $datos): Categoria
    {
        return DB::transaction(function () use ($categoria, $datos) {
            $cambioElNombre = $categoria->nombre !== $datos['nombre'];

            // Se enumeran los campos en lugar de pasar $datos entero: lo que no
            // está en el formulario (peso_default_gramos) no se toca.
            $categoria->update([
                'parent_id' => $datos['parent_id'] ?? null,
                'nombre'    => $datos['nombre'],
                'slug'      => $cambioElNombre
                    ? Slug::unicoPara(Categoria::class, $datos['nombre'], $categoria->id)
                    : $categoria->slug,
                'orden'     => $datos['orden'],
                'activo'    => $datos['activo'],
            ]);

            return $categoria->fresh();
        });
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminar(Categoria $categoria): bool
    {
        if ($categoria->hijas()->exists() || $categoria->productos()->exists()) {
            $categoria->update(['activo' => false]);

            return false;
        }

        DB::transaction(fn () => $categoria->delete());

        return true;
    }

    /**
     * Categorías que se pueden elegir como padre de $categoria (null en el alta).
     *
     * Es comodidad, no control: el control es CategoriaRequest, que rechaza
     * igual una petición armada a mano. Esto evita ofrecer opciones que después
     * se van a rechazar.
     *
     * Excluye la propia categoría, sus descendientes (ciclo), las inactivas y
     * las que dejarían el subárbol por debajo del nivel máximo. El padre ACTUAL
     * se ofrece aunque esté inactivo: si no apareciera en el select, el
     * formulario se abriría con "Ninguna" seleccionado y al guardar la
     * categoría se mudaría a la raíz sin que nadie lo pidiera.
     *
     * @return Collection<int, Categoria>
     */
    public function padresPosibles(?Categoria $categoria = null): Collection
    {
        $excluir = $categoria ? [$categoria->id, ...$categoria->idsDescendientes()] : [];
        $altura  = $categoria?->alturaSubarbol() ?? 1;

        return Categoria::query()
            ->with('padre.padre')
            ->where(fn ($query) => $query
                ->where('activo', true)
                ->when($categoria?->parent_id, fn ($query, $padreActual) => $query->orWhere('id', $padreActual)))
            ->whereKeyNot($excluir)
            ->get()
            ->filter(fn (Categoria $opcion) => $opcion->nivelCargado() + $altura <= Categoria::PROFUNDIDAD_MAXIMA)
            ->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}