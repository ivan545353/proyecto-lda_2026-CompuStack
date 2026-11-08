<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Los números únicos del comercio: la ganancia de la que cuelga la cascada, el
 * recargo del precio de lista y la política de redondeo.
 *
 * Una sola fila, la 1, garantizada por una restricción CHECK en la base. No
 * autoincrementa porque no hay una segunda que numerar.
 */
class PoliticaPrecios extends Model
{
    /** La única fila que la tabla admite. */
    public const FILA = 1;

    public const MODOS_REDONDEO = [
        'cercano' => 'Al múltiplo más cercano',
        'arriba'  => 'Siempre hacia arriba',
        'abajo'   => 'Siempre hacia abajo',
    ];

    protected $table = 'politica_precios';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['ganancia_global', 'recargo_lista', 'redondeo_multiplo', 'redondeo_modo'];

    protected function casts(): array
    {
        return [
            'ganancia_global'   => 'decimal:3',
            'recargo_lista'     => 'decimal:3',
            'redondeo_multiplo' => 'decimal:2',
        ];
    }

    /** La política vigente. La escribe la migración, así que siempre existe. */
    public static function vigente(): self
    {
        return static::findOrFail(self::FILA);
    }

    /** El margen sobre la venta que equivale al multiplicador de ganancia global. */
    public function margenGlobal(): float
    {
        $multiplicador = (float) $this->ganancia_global;

        return $multiplicador > 0 ? round((1 - (1 / $multiplicador)) * 100, 2) : 0.0;
    }
}