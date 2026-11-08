<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Empleado extends Model
{
    use HasFactory;

    protected $table = 'empleados';

    protected $fillable = ['user_id', 'legajo', 'dni', 'telefono', 'fecha_ingreso', 'fecha_baja'];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'fecha_baja'    => 'date',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
