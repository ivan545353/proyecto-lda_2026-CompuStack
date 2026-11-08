<?php

namespace App\Models;

use App\Support\Importe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un ajuste masivo de precios, con su criterio, su parámetro y su resultado.
 *
 * Relaciones:
 *   usuario()    BelongsTo  quién lo corrió; null es el sistema
 *   cambios()    HasMany    las filas de historial que escribió
 *   revertido()  BelongsTo  el lote que este deshace
 *   reversion()  HasOne     el lote que deshizo a este, si alguno lo hizo
 */
class LoteAjuste extends Model
{
    /** Las operaciones, con su texto para la pantalla. */
    public const OPERACIONES = [
        'costo'     => 'Variar el costo un porcentaje',
        'precio'    => 'Variar el precio un porcentaje',
        'ganancia'  => 'Fijar el multiplicador de ganancia',
        'recalculo' => 'Recalcular desde los factores',
        'redondeo'  => 'Redondear a un múltiplo',
        'deshacer'  => 'Deshacer un ajuste anterior',
    ];

    /** Las que la pantalla ofrece: `deshacer` no se elige, se hace con su botón. */
    public const ELEGIBLES = ['costo', 'precio', 'ganancia', 'recalculo', 'redondeo'];

    public const UPDATED_AT = null;

    protected $table = 'lotes_ajuste';

    protected $fillable = [
        'usuario_id', 'operacion', 'porcentaje', 'ganancia', 'redondeo_multiplo',
        'criterio', 'criterio_texto', 'alcanzados', 'omitidos', 'revierte_a',
    ];

    protected function casts(): array
    {
        return [
            'porcentaje'        => 'decimal:3',
            'ganancia'          => 'decimal:3',
            'redondeo_multiplo' => 'decimal:2',
            'criterio'          => 'array',
            'alcanzados'        => 'integer',
            'omitidos'          => 'integer',
            'created_at'        => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /** Las filas de historial que escribió este lote, una por producto alcanzado. */
    public function cambios(): HasMany
    {
        return $this->hasMany(HistorialPrecio::class, 'lote_id');
    }

    /** El lote que este deshace, cuando la operación es `deshacer`. */
    public function revertido(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revierte_a');
    }

    /** El lote que deshizo a este, si alguno lo hizo. */
    public function reversion(): HasOne
    {
        return $this->hasOne(self::class, 'revierte_a');
    }

    /** Del más nuevo al más viejo, que es como se lee un historial. */
    public function scopeRecientes(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Los que todavía se pueden deshacer.
     *
     * Dos condiciones, y las dos son de los datos y no de una bandera: que nadie lo
     * haya revertido ya, y que no sea él mismo una reversión. Deshacer un «deshacer»
     * es volver a aplicar el ajuste, y para eso está volver a aplicarlo.
     */
    public function scopeReversibles(Builder $query): Builder
    {
        return $query->where('operacion', '!=', 'deshacer')->whereDoesntHave('reversion');
    }

    /**
     * El último lote que se puede deshacer, o null si no hay ninguno.
     *
     * Sólo el último, a propósito: el precio anterior que guarda el historial vale
     * mientras nada haya pasado después. Revertir el lote de la semana pasada
     * devolvería precios que dos ajustes posteriores ya movieron.
     */
    public static function ultimoReversible(): ?self
    {
        return static::query()->reversibles()->recientes()->first();
    }

    /** El parámetro de la operación, escrito; null cuando la operación no lleva. */
    public function parametro(): ?string
    {
        return match ($this->operacion) {
            'costo', 'precio' => Importe::numero($this->porcentaje).' %',
            'ganancia'        => '× '.Importe::numero($this->ganancia),
            'redondeo'        => 'Múltiplo de '.Importe::numero($this->redondeo_multiplo, 2),
            default           => null,
        };
    }

    /** Quién lo corrió. Sin usuario fue el sistema, y eso se dice con palabras. */
    public function usuarioTexto(): string
    {
        return $this->usuario?->nombre_completo ?? 'El sistema';
    }
}