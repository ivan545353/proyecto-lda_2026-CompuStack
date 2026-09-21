<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marca comercial de los productos. Módulo nuevo: no existe en el sistema
 * original, donde la marca era parte del nombre del producto.
 *
 * El modelo tiene relaciones y scopes de consulta, nada más. La validación
 * vive en MarcaRequest y las reglas de negocio en MarcaService.
 *
 * Scopes (un scope por filtro del listado, con el MISMO nombre que el
 * parámetro de la URL):
 *   buscar($texto)      nombre contiene
 *   conEstado($estado)  activas | inactivas | sin filtrar
 *
 * Que el scope y el parámetro se llamen igual es la corrección directa del
 * hallazgo A-24: el ItemController original enviaba `categoriaId` y el ItemDao
 * leía `categoria`, así que ningún filtro se aplicaba. Sin tests, el bug
 * sobrevivió a la versión final del sistema.
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

    /**
     * Filtro por nombre.
     *
     * Sin texto no filtra: un buscador vacío muestra todo, no cero resultados.
     *
     * Los comodines de LIKE se escapan. El binding de PDO protege de la
     * inyección, pero no del significado: quien escribe "100%" en el buscador
     * espera buscar ese texto, no traer la tabla entera.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $patron = addcslashes(trim($texto), '%_\\');

            return $query->where('nombre', 'like', "%{$patron}%");
        });
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