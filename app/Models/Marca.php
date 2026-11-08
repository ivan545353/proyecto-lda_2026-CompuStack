<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Like;

/**
    * Marca comercial de los productos. Módulo nuevo: no existe en el sistema
    * original, donde la marca era parte del nombre del producto.
    *
    * El modelo tiene relaciones y scopes de consulta, nada más. La validación
    * vive en MarcaRequest y las reglas de negocio en MarcaService.
    *
    * Scopes (uno por filtro del listado; la correspondencia con el parámetro de
    * la URL está declarada acá y en MarcaFiltroRequest):
    *   ?q=       → buscar($texto)      nombre contiene
    *   ?estado=  → conEstado($estado)  activas | inactivas | sin filtrar
    *
    * Tener el contrato escrito en los dos extremos es la corrección del hallazgo
    * A-24: el ItemController original enviaba `categoriaId` y el ItemDao leía
    * `categoria`, y no había ningún lugar donde constara cuál era el correcto.
    * Sin tests, el bug sobrevivió a la versión final del sistema.
*/
class Marca extends Model
{
    use HasFactory;

    protected $table = 'marcas';

    protected $fillable = ['nombre', 'slug', 'logo', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'marca_id');
    }

    /** Productos activos de la marca: se muestra antes de desactivarla. */
    public function productosActivos(): int
    {
        return $this->productos()->where('activo', true)->count();
    }

    /**
     * Filtro por nombre.
     *
     * Sin texto no filtra: un buscador vacío muestra todo, no cero resultados.
     *
     * Los comodines de LIKE son texto literal (ver App\Support\Like).
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), fn (Builder $query) => $query
            ->where('nombre', 'like', Like::contiene($texto)));
    }

    /**
     * Filtro por estado.
     *
     * El valor vacío no filtra. Un valor desconocido tampoco: el listado
     * muestra todo en lugar de inventar un criterio. Igual el controlador lo
     * valida contra la lista cerrada, así que el usuario recibe el aviso en
     * vez de un resultado silenciosamente distinto al que pidió.
     */
    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            'activas'   => $query->where('activo', true),
            'inactivas' => $query->where('activo', false),
            default     => $query,
        };
    }
}