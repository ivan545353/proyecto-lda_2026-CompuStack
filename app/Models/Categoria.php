<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasFactory;

    protected $table = 'categorias';

    protected $fillable = ['parent_id', 'nombre', 'slug', 'orden', 'peso_default_gramos', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'parent_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(Categoria::class, 'parent_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_id');
    }
}
