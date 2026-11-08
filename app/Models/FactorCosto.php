<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un factor que FORMA el neto: flete, IIBB que el comercio absorbe, lo que el
 * negocio agregue. No se itemiza: va adentro del precio.
 *
 * `modo` es lo que evita el error clásico. Un impuesto que se calcula sobre la
 * venta no se recupera multiplicando por 1,03 —eso recupera 2,91 %— sino
 * dividiendo por 0,97. Declarado en el dato, el motor lo hace bien sin que
 * nadie lo piense al cargar el factor.
 */
class FactorCosto extends Model
{
    public const MODOS = [
        'recargo'  => 'Recargo sobre el costo (multiplica por 1 + %)',
        'recupero' => 'Recupero sobre la venta (divide por 1 − %)',
    ];

    protected $table = 'factores_costo';

    protected $fillable = ['nombre', 'modo', 'porcentaje', 'orden', 'activo'];

    protected $attributes = ['orden' => 0, 'activo' => true];

    protected function casts(): array
    {
        return [
            'porcentaje' => 'decimal:3',
            'orden'      => 'integer',
            'activo'     => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /** El orden en que se aplican, que es el que se ve en el desglose. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('orden')->orderBy('id');
    }
}