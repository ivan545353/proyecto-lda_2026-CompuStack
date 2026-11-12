<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Un intento de pagar un pedido por una pasarela (tramo G). No mueve plata: la mueve el pago
 * que lo acredita (`pagos.intento_pago_id`). Todo lo escribe `IntentoPagoService`, campo por campo.
 *
 * Relaciones:
 *   venta()  BelongsTo   el pedido que se intenta pagar
 *   pago()   HasOne      el pago que lo acreditó; uno como mucho (UNIQUE, HL-2)
 */
class IntentoPago extends Model
{
    public const PASARELAS = ['simulador', 'mercadopago'];

    public const ESTADOS = ['pendiente', 'aprobado', 'rechazado', 'vencido', 'error'];

    protected $table = 'intentos_pago';

    protected $attributes = ['estado' => 'pendiente'];

    protected function casts(): array
    {
        return [
            'importe'       => 'decimal:2',
            'vence_at'      => 'datetime',
            'resuelto_at'   => 'datetime',
            'acreditado_at' => 'datetime',
        ];
    }

    // Un intento es un hecho, como el pago: se resuelve, no se borra (M-16).
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Un intento de pago no se borra: se resuelve o vence.');
        });
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function pago(): HasOne
    {
        return $this->hasOne(Pago::class, 'intento_pago_id');
    }

    /**
     * El proveedor aprobó un importe que no es el del intento (HL-72). Queda aprobado —la plata
     * entró— pero no se acredita solo: lo revisa una persona con el `payment_id`.
     */
    public const IMPORTE_DISTINTO = 'importe_distinto';

    /** Aprobado por el proveedor y todavía sin su fila en `pagos` (§1.3 E). */
    public function aprobadoSinAcreditar(): bool
    {
        return $this->estado === 'aprobado' && $this->acreditado_at === null;
    }

    /** Lo que el sistema puede acreditar sin una persona: aprobado, sin acreditar y por el importe pedido. */
    public function acreditable(): bool
    {
        return $this->aprobadoSinAcreditar() && $this->detalle !== self::IMPORTE_DISTINTO;
    }
}
