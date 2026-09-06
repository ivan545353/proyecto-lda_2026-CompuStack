<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Direccion extends Model
{
    use HasFactory;

    // Sin esto Eloquent buscaría la tabla 'direccions'.
    protected $table = 'direcciones';

    protected $fillable = [
        'cliente_id', 'calle', 'numero', 'piso_depto',
        'codigo_postal', 'localidad', 'provincia', 'es_predeterminada',
    ];

    protected function casts(): array
    {
        return ['es_predeterminada' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
