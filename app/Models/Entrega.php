<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Una entrega de mercadería de una venta: el comprobante interno de entrega.
 *
 * No es un remito RG 1415 ni lleva CAI; el porqué está en la migración y en
 * docs/modelo-datos.md. Es un hecho: no se modifica ni se borra.
 */
class Entrega extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'entregas';

    protected $fillable = ['venta_id', 'usuario_id'];

    /** Una entrega no se corrige: si algo salió mal, el hecho siguiente es una devolución. */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Una entrega no se modifica: es un hecho que ya pasó.');
        });

        static::deleting(function () {
            throw new LogicException('Una entrega no se borra: su comprobante tiene que poder reimprimirse.');
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    /** Quién la entregó; null si la registró el sistema. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(EntregaLinea::class, 'entrega_id')->orderBy('id');
    }

    /** El formato del número, a partir del id: E-00042. */
    public static function numeroDe(int|string $id): string
    {
        return 'E-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function numeroFormateado(): string
    {
        return self::numeroDe($this->id);
    }

    /**
     * Cuánto de cada renglón de esta entrega se llevaba entregado al cerrarla, sumado en la base.
     *
     * @return array<int, int>  [id de renglón de la venta => unidades]
     */
    public function acumuladoPorRenglon(): array
    {
        return EntregaLinea::query()
            ->whereIn('venta_linea_id', $this->lineas->pluck('venta_linea_id'))
            ->where('entrega_id', '<=', $this->id)
            ->groupBy('venta_linea_id')
            ->selectRaw('venta_linea_id, SUM(cantidad) AS acumulado')
            ->pluck('acumulado', 'venta_linea_id')
            ->map(fn ($unidades) => (int) $unidades)
            ->all();
    }

    /** Quién entregó, en palabras, para la ficha y el comprobante. */
    public function quienTexto(): string
    {
        return $this->usuario
            ? trim("{$this->usuario->nombre} {$this->usuario->apellido}")
            : 'Registrada por el sistema';
    }
}