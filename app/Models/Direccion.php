<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dirección habitual de un cliente. Sólo se usa para envíos.
 *
 * No tiene existencia autónoma: si se va el cliente, se va con él. 
 * Por eso tampoco tiene permisos propios — se administra con
 * `cliente.editar`.
 *
 * La dirección de un envío NO se referencia desde acá: se copia (Etapa 2), para
 * que corregir una dirección hoy no reescriba adónde se mandó un paquete el año
 * pasado.
 *
 * Invariante, garantizada por DireccionService: si el cliente tiene al menos
 * una dirección, exactamente una tiene `es_predeterminada`. El esquema no puede
 * expresarlo —un boolean con default false acepta tres marcadas o ninguna— así
 * que vive en la capa de aplicación, igual que la invariante rol↔satélite.
 *
 * Relaciones:
 *   cliente()  BelongsTo
 *
 * Métodos:
 *   resumen()  la dirección en una línea, para listados y confirmaciones
 */
class Direccion extends Model
{
    use HasFactory;

    /**
     * Las 24 jurisdicciones del país.
     *
     * Lista cerrada y no texto libre: con texto libre, "Santa Cruz", "Sta Cruz"
     * y "SANTA CRUZ" son tres provincias distintas, y la cotización de envío de
     * la Etapa 2 necesita una sola. Se valida contra esta lista y el selector de
     * la vista se arma con ella, así que los dos comparan lo mismo.
     */
    public const PROVINCIAS = [
        'Buenos Aires',
        'Ciudad Autónoma de Buenos Aires',
        'Catamarca',
        'Chaco',
        'Chubut',
        'Córdoba',
        'Corrientes',
        'Entre Ríos',
        'Formosa',
        'Jujuy',
        'La Pampa',
        'La Rioja',
        'Mendoza',
        'Misiones',
        'Neuquén',
        'Río Negro',
        'Salta',
        'San Juan',
        'San Luis',
        'Santa Cruz',
        'Santa Fe',
        'Santiago del Estero',
        'Tierra del Fuego',
        'Tucumán',
    ];

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

    /**
     * La dirección en una línea.
     *
     * Vive acá y no en la vista para que el listado, el formulario y el diálogo
     * de confirmación de la baja digan exactamente lo mismo.
     */
    public function resumen(): string
    {
        $calle = trim("{$this->calle} {$this->numero}");

        if (filled($this->piso_depto)) {
            $calle .= ", {$this->piso_depto}";
        }

        return "{$calle} — {$this->localidad}, {$this->provincia} ({$this->codigo_postal})";
    }
}