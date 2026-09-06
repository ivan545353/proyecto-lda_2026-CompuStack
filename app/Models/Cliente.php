<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = [
        'user_id', 'razon_social', 'tipo_doc', 'nro_doc',
        'condicion_iva', 'email', 'telefono',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function direcciones(): HasMany
    {
        return $this->hasMany(Direccion::class, 'cliente_id');
    }

    /** Responsable inscripto lleva Factura A; el resto, B. (Etapa 2) */
    public function tipoComprobante(): string
    {
        return $this->condicion_iva === 'responsable_inscripto' ? 'factura_a' : 'factura_b';
    }
}
