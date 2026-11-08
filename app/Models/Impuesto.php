<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un impuesto que se aplica SOBRE el neto y se itemiza en el comprobante.
 *
 * Los de alcance 'producto' son las alícuotas de IVA: cada producto elige una y
 * la guarda como número en `productos.alicuota_iva`. Los de alcance 'general'
 * alcanzan a todo el catálogo.
 *
 * El producto guarda el NÚMERO y no una clave foránea a propósito: la alícuota
 * la fija la ley, y con clave foránea editar esta fila le cambiaría el IVA en
 * silencio a cada producto que la apuntara. Acá el catálogo gobierna qué se
 * puede elegir; el valor elegido vive en el producto, igual que ya vive
 * congelado en `venta_lineas`.
 */
class Impuesto extends Model
{
    /** Cómo llega el impuesto al producto. */
    public const ALCANCES = [
        'producto' => 'Alícuota de IVA: cada producto elige una',
        'general'  => 'Alcanza a todos los productos',
    ];

    /** Cómo se declara en el comprobante, según la Ley 27.743. */
    public const COMPROBANTES = [
        'iva'              => 'IVA contenido',
        'otros_nacionales' => 'Otros impuestos nacionales indirectos',
        'no_se_muestra'    => 'No se declara en el comprobante',
    ];

    protected $table = 'impuestos';

    protected $fillable = ['nombre', 'alicuota', 'alcance', 'comprobante', 'orden', 'activo'];

    protected $attributes = ['orden' => 0, 'activo' => true];

    protected function casts(): array
    {
        return [
            'alicuota' => 'decimal:2',
            'orden'    => 'integer',
            'activo'   => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    public function scopeDeAlcance(Builder $query, ?string $alcance): Builder
    {
        return $query->when(
            array_key_exists((string) $alcance, self::ALCANCES),
            fn (Builder $query) => $query->where('alcance', $alcance),
        );
    }

    /** El orden del desglose, que es el de la pantalla de factores. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }

    /**
     * Las alícuotas de IVA que un producto puede elegir, como las espera el
     * selector: clave '21.00' y texto 'IVA 21 %'. Reemplaza a la constante
     * Producto::ALICUOTAS_IVA, que era la misma lista fija en el código.
     *
     * @return array<string, string>
     */
    public static function opcionesDeAlicuota(): array
    {
        return static::query()->activos()->deAlcance('producto')->ordenados()
            ->pluck('nombre', 'alicuota')
            ->all();
    }
}