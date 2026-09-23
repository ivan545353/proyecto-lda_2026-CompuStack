<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Like;

/**
 * Categoría del catálogo, con jerarquía por autorreferencia
 * (Componentes › Almacenamiento › SSD).
 *
 * En el sistema original era una lista plana de (id, nombre). La jerarquía es
 * un pedido nuevo de la Etapa 1.
 *
 * Por qué autorreferencia simple y no un árbol anidado (nested set): con pocos
 * niveles, subir o bajar por el árbol cuesta una consulta por nivel, y un
 * nested set obliga a renumerar medio árbol en cada alta. PROFUNDIDAD_MAXIMA
 * convierte ese "pocos niveles" en regla: la decisión de diseño se sostiene
 * porque el código no permite que deje de ser cierta.
 *
 * Scopes (uno por filtro del listado; la correspondencia con el parámetro de
 * la URL está declarada acá y en CategoriaFiltroRequest):
 *   ?q=          → buscar($texto)      nombre contiene
 *   ?parent_id=  → dePadre($padre)     'raiz' = primer nivel; id = hijas directas
 *   ?estado=     → conEstado($estado)  activas | inactivas | sin filtrar
 *
 * Jerarquía:
 *   idsDescendientes()  hijas, nietas… (para impedir ciclos al elegir padre)
 *   nivel()             1 = raíz
 *   alturaSubarbol()    1 = hoja
 *   ruta                "Componentes › Placas de video"
 */
class Categoria extends Model
{
    use HasFactory;

    public const PROFUNDIDAD_MAXIMA = 3;

    protected $table = 'categorias';

    protected $fillable = ['parent_id', 'nombre', 'slug', 'orden', 'peso_default_gramos', 'activo'];

    protected function casts(): array
    {
        return [
            // Entero y no string: las comparaciones de la jerarquía son
            // estrictas, y '3' !== 3 haría fallar la detección de ciclos.
            'parent_id'           => 'integer',
            'orden'               => 'integer',
            'peso_default_gramos' => 'integer',
            'activo'              => 'boolean',
        ];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'parent_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(Categoria::class, 'parent_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    // Los comodines de LIKE son texto literal (ver App\Support\Like).
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), fn (Builder $query) => $query
            ->where('nombre', 'like', Like::contiene($texto)));
    }

    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            'activas'   => $query->where('activo', true),
            'inactivas' => $query->where('activo', false),
            default     => $query,
        };
    }

    /**
     * Filtro por padre. Devuelve las hijas DIRECTAS, no la rama completa: es la
     * decisión del contrato de filtros (categoría exacta, sin recursión).
     *
     * 'raiz' es un valor explícito y no un parent_id vacío, porque vacío ya
     * significa "sin filtrar". Si las dos cosas se escribieran igual, el
     * usuario no tendría forma de pedir sólo las de primer nivel.
     */
    public function scopeDePadre(Builder $query, int|string|null $padre): Builder
    {
        return match (true) {
            $padre === 'raiz'              => $query->whereNull('parent_id'),
            ctype_digit((string) $padre)   => $query->where('parent_id', (int) $padre),
            default                        => $query,
        };
    }

    // ------------------------------------------------------------------
    // Jerarquía
    // ------------------------------------------------------------------

    /**
     * Ids de todas las descendientes: hijas, nietas, etcétera.
     *
     * Es lo que impide elegir como padre a una descendiente, que convertiría
     * el árbol en un ciclo.
     *
     * @return array<int, int>
     */
    public function idsDescendientes(): array
    {
        return array_merge(...$this->nivelesInferiores());
    }

    /** Cantidad de niveles del subárbol, contando esta categoría. Una hoja mide 1. */
    public function alturaSubarbol(): int
    {
        return 1 + count($this->nivelesInferiores());
    }

        /**
     * Qué queda activo dentro de esta categoría: subcategorías y productos de
     * toda la rama.
     *
     * Se usa para avisarle al usuario qué pasa al desactivarla, y para armar
     * el mensaje del resultado.
     *
     * @return array{subcategorias: int, productos: int}
     */
    public function contenidoActivo(): array
    {
        $rama = [$this->id, ...$this->idsDescendientes()];

        return [
            'subcategorias' => static::query()->whereIn('id', $rama)->whereKeyNot($this->id)
                ->where('activo', true)->count(),
            'productos' => Producto::query()->whereIn('categoria_id', $rama)
                ->where('activo', true)->count(),
        ];
    }

    /** Nivel en el árbol. Una raíz es nivel 1. */
    public function nivel(): int
    {
        $nivel   = 1;
        $padreId = $this->parent_id;
        $vistos  = [$this->id];

        // $vistos es defensivo: si un ciclo llegara a existir en la base (por
        // una carga a mano, por ejemplo), el recorrido termina igual en vez de
        // colgar la petición.
        while ($padreId !== null && ! in_array($padreId, $vistos, true)) {
            $vistos[] = $padreId;
            $padreId  = static::whereKey($padreId)->value('parent_id');
            $nivel++;
        }

        return $nivel;
    }

        /**
     * Nivel calculado sobre las relaciones ya cargadas, sin consultar la base.
     *
     * Para listas: con with('padre.padre') alcanza para conocer el nivel de
     * cualquier categoría del árbol, porque no hay más de tres. nivel() hace
     * una consulta por llamada y en un selector de cincuenta opciones serían
     * cincuenta consultas.
     */
    public function nivelCargado(): int
    {
        $nivel  = 1;
        $actual = $this->padre;

        while ($actual !== null && $nivel < self::PROFUNDIDAD_MAXIMA) {
            $nivel++;
            $actual = $actual->padre;
        }

        return $nivel;
    }

    /**
     * "Componentes › Placas de video".
     *
     * Recorre las relaciones ya cargadas: el listado debe pedir
     * with('padre.padre') para que esto no haga una consulta por fila.
     */
    public function getRutaAttribute(): string
    {
        $partes = [$this->nombre];
        $actual = $this->padre;

        for ($n = 1; $actual !== null && $n < self::PROFUNDIDAD_MAXIMA; $n++) {
            array_unshift($partes, $actual->nombre);
            $actual = $actual->padre;
        }

        return implode(' › ', $partes);
    }

    /**
     * Ids de los niveles inferiores, agrupados por nivel. Una consulta por
     * nivel, así que con PROFUNDIDAD_MAXIMA = 3 son como mucho tres.
     *
     * @return array<int, array<int, int>>
     */
    private function nivelesInferiores(): array
    {
        $niveles = [];
        $vistos  = [$this->id];
        $actual  = [$this->id];

        while (true) {
            // whereKeyNot($vistos): la misma defensa contra ciclos que en nivel().
            $actual = static::whereIn('parent_id', $actual)
                ->whereKeyNot($vistos)
                ->pluck('id')
                ->all();

            if ($actual === []) {
                return $niveles;
            }

            $niveles[] = $actual;
            $vistos    = array_merge($vistos, $actual);
        }
    }
}