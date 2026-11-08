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
 *   ventas()                   HasMany    su historial de compras
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

    /**
     * Su historial de compras.
     */
    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'cliente_id');
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

    /** «DNI 41556778», o null si no tiene documento cargado. */
    public function documento(): ?string
    {
        return blank($this->nro_doc)
            ? null
            : trim((self::TIPOS_DOC[$this->tipo_doc] ?? '').' '.$this->nro_doc);
    }

    /**
     * Cómo se nombra un cliente cuando hay que elegirlo.
     */
    public function razonConDocumento(): string
    {
        $documento = $this->documento();

        return $documento === null
            ? $this->razon_social
            : "{$this->razon_social} — {$documento}";
    }

    /**
     * La fila con la que un cliente llega al buscador, venga de una búsqueda o de un alta.
     *
     * @return array{id: int, texto: string, razon_social: string, documento: ?string}
     */
    public function resultadoDeBusqueda(): array
    {
        return [
            'id'           => $this->id,
            'texto'        => $this->razonConDocumento(),
            'razon_social' => $this->razon_social,
            'documento'    => $this->documento(),
        ];
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Busca por razón social, documento, correo o teléfono.
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