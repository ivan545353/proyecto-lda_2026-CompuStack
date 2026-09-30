<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cliente: el sujeto de una venta.
 *
 * Relaciones:
 *   usuario()                  BelongsTo  su cuenta, si tiene
 *   direcciones()              HasMany    las habituales, para envíos
 *   direccionPredeterminada()  HasOne     la que se ofrece primero
 *
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * ClienteFiltroRequest):
 *   ?q=              → buscar($texto)            razón social, documento, correo o teléfono
 *   ?condicion_iva=  → conCondicionIva($valor)   lista cerrada
 *   ?cuenta=         → conCuenta($valor)         con_cuenta | sin_cuenta
 *
 * Métodos:
 *   esDeMostrador()    si no tiene cuenta de acceso
 *   tipoComprobante()  factura_a o factura_b (Etapa 2)
 */
class Cliente extends Model
{
    use HasFactory;

    /**
     * Condiciones frente al IVA, con su texto para la pantalla.
     *
     * La clave es exactamente el valor del ENUM de la base. La usan el Form
     * Request para validar, el scope para filtrar y la vista para armar el
     * selector, así que los tres comparan la misma lista. Es el mismo criterio
     * que Producto::ALICUOTAS_IVA.
     */
    public const CONDICIONES_IVA = [
        'consumidor_final'      => 'Consumidor final',
        'responsable_inscripto' => 'Responsable inscripto',
        'monotributo'           => 'Monotributo',
        'exento'                => 'Exento',
    ];

    public const TIPOS_DOC = [
        'dni'  => 'DNI',
        'cuit' => 'CUIT',
        'cuil' => 'CUIL',
    ];

    /** Dígitos mínimos para interpretar un texto de búsqueda como documento. */
    private const DIGITOS_MINIMOS_DOC = 6;

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

    /** La que se ofrece primero al armar un envío (Etapa 2). */
    public function direccionPredeterminada(): HasOne
    {
        return $this->hasOne(Direccion::class, 'cliente_id')->where('es_predeterminada', true);
    }

    /** Cliente de mostrador: compró sin cuenta y no necesita una. */
    public function esDeMostrador(): bool
    {
        return $this->user_id === null;
    }

    /** Responsable inscripto lleva Factura A; el resto, B. (Etapa 2) */
    public function tipoComprobante(): string
    {
        return $this->condicion_iva === 'responsable_inscripto' ? 'factura_a' : 'factura_b';
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Busca por razón social, documento, correo o teléfono.
     *
     * Un CUIT se lee de una factura escrito `30-71555888-1` y se guarda
     * `30715558881`. Quien lo pega tal cual no puede recibir cero resultados, así
     * que el texto se busca también sin separadores contra `nro_doc`.
     *
     * Sólo si quedan al menos 6 dígitos, que es el mínimo de un DNI: sin ese
     * piso, buscar "Austral 2" traería a todos los clientes que tengan un 2 en
     * el documento.
     *
     * El OR va agrupado en un closure. Sin el grupo, encadenar otro filtro
     * después produce `(a AND b) OR c` en lugar de `a AND (b OR c)`, y el
     * listado devolvería filas que no cumplen el otro filtro.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            $patron = Like::contiene($texto);

            $digitos     = preg_replace('/\D/', '', (string) $texto);
            $esDocumento = strlen($digitos) >= self::DIGITOS_MINIMOS_DOC;

            return $query->where(function (Builder $query) use ($patron, $digitos, $esDocumento) {
                $query->where('razon_social', 'like', $patron)
                    ->orWhere('email', 'like', $patron)
                    ->orWhere('telefono', 'like', $patron);

                if ($esDocumento) {
                    $query->orWhere('nro_doc', 'like', Like::contiene($digitos));
                }
            });
        });
    }

    /**
     * Filtro por condición frente al IVA.
     *
     * Un valor desconocido no filtra: el listado muestra todo en lugar de
     * inventar un criterio. El Form Request igual lo valida contra la lista
     * cerrada, así que el usuario recibe el aviso.
     */
    public function scopeConCondicionIva(Builder $query, ?string $condicion): Builder
    {
        return $query->when(
            array_key_exists((string) $condicion, self::CONDICIONES_IVA),
            fn (Builder $query) => $query->where('condicion_iva', $condicion),
        );
    }

    /** Separa al cliente de mostrador del que tiene cuenta de tienda. */
    public function scopeConCuenta(Builder $query, ?string $valor): Builder
    {
        return match ($valor) {
            'con_cuenta' => $query->whereNotNull('user_id'),
            'sin_cuenta' => $query->whereNull('user_id'),
            default      => $query,
        };
    }
}