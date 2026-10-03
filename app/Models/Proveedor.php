<?php

namespace App\Models;

use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Proveedor de mercadería. Módulo nuevo: el sistema original no tenía
 * proveedores, ni productos.proveedorId, ni compras.
 *
 * Relaciones:
 *   productos()  HasMany  los que se le compran
 *
 * (`ordenesCompra()` se agrega cuando exista el modelo OrdenCompra.)
 *
 * Scopes (uno por filtro del listado; el contrato está declarado acá y en
 * ProveedorFiltroRequest):
 *   ?q=       → buscar($texto)      razón social, CUIT, contacto o correo
 *   ?estado=  → conEstado($estado)  activos | inactivos
 *   ?canal=   → conCanal($canal)    lista cerrada de CANALES
 *
 * Tener el contrato escrito en los dos extremos es la corrección de fondo de
 * A-24: el ItemController original mandaba `categoriaId`, el ItemDao leía
 * `categoria`, y no existía ningún lugar donde constara cuál era el correcto.
 */
class Proveedor extends Model
{
    use HasFactory;

    /**
     * Canales de pedido, con su texto para la pantalla.
     *
     * La clave es exactamente el valor del ENUM de la base. La usan el Form
     * Request para validar, el scope para filtrar y la vista para armar el
     * selector, así que los tres comparan la misma lista. Es el mismo criterio
     * que Producto::ALICUOTAS_IVA y Cliente::CONDICIONES_IVA.
     */
    public const CANALES = [
        'email'          => 'Correo electrónico',
        'portal_externo' => 'Portal del proveedor',
        'manual'         => 'Pedido manual',
    ];

    /** Dígitos mínimos para interpretar el texto buscado como un CUIT. */
    private const DIGITOS_MINIMOS_CUIT = 6;

    protected $table = 'proveedores';

    protected $fillable = [
        'razon_social', 'cuit', 'email', 'telefono', 'contacto',
        'canal_pedido', 'portal_url', 'plazo_entrega_dias', 'activo',
    ];

    protected function casts(): array
    {
        return [
            // Los enteros se declaran igual que los importes (A-17): sin el
            // cast, MariaDB devuelve el SMALLINT como string y una comparación
            // estricta en la vista o en el servicio falla sin decir por qué.
            'plazo_entrega_dias' => 'integer',
            'activo'             => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'proveedor_id');
    }
    
    /** Las compras que se le hicieron. El historial de pedidos del proveedor. */
    public function ordenesCompra(): HasMany
    {
        return $this->hasMany(OrdenCompra::class, 'proveedor_id');
    }

    /** Productos activos del proveedor: se muestra antes de desactivarlo. */
    public function productosActivos(): int
    {
        return $this->productos()->where('activo', true)->count();
    }

    /** Texto del canal para la pantalla. Nunca se muestra el valor del ENUM. */
    public function canalPedidoTexto(): string
    {
        return self::CANALES[$this->canal_pedido] ?? $this->canal_pedido;
    }

    /**
     * El CUIT como se lee en una factura: 30-71234567-8.
     *
     * Se guarda normalizado en once dígitos y se muestra formateado. Es la
     * contracara de la normalización: una sola forma de guardar, una sola forma
     * de leer. El `if` es defensa de presentación, no validación —de eso se
     * ocupa el Form Request—: una fila vieja o importada a mano no rompe la
     * pantalla.
     */
    public function cuitFormateado(): string
    {
        return preg_match('/^\d{11}$/', (string) $this->cuit) === 1
            ? substr($this->cuit, 0, 2).'-'.substr($this->cuit, 2, 8).'-'.substr($this->cuit, 10)
            : (string) $this->cuit;
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Busca por razón social, CUIT, persona de contacto o correo.
     *
     * Un CUIT se lee de una factura escrito `30-71234567-8` y se guarda
     * `30712345678`. Quien lo pega tal cual no puede recibir cero resultados,
     * así que el texto se busca también sin separadores contra `cuit`. Es el
     * mismo criterio que Cliente::scopeBuscar() usa con `nro_doc`.
     *
     * Sólo si quedan al menos 6 dígitos, que es el mínimo de un documento: sin
     * ese piso, buscar "Austral 2" traería a todo proveedor que tenga un 2 en
     * el CUIT.
     *
     * El OR va agrupado en un closure. Sin el grupo, encadenar ?estado=
     * después produce `(razon_social LIKE … ) OR (email LIKE … AND activo = 1)`
     * en lugar de `(… OR …) AND activo = 1`, y el listado devuelve
     * proveedores inactivos a quien pidió ver sólo los activos.
     *
     * Sin texto no filtra: un buscador vacío muestra todo, no cero resultados.
     */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        return $query->when(filled($texto), function (Builder $query) use ($texto) {
            // Los comodines de LIKE son texto literal (ver App\Support\Like):
            // quien busca "100%" espera ese texto, no la tabla entera.
            $patron = Like::contiene($texto);

            $digitos = preg_replace('/\D/', '', (string) $texto);
            $esCuit  = strlen($digitos) >= self::DIGITOS_MINIMOS_CUIT;

            return $query->where(function (Builder $query) use ($patron, $digitos, $esCuit) {
                $query->where('razon_social', 'like', $patron)
                    ->orWhere('contacto', 'like', $patron)
                    ->orWhere('email', 'like', $patron);

                if ($esCuit) {
                    $query->orWhere('cuit', 'like', Like::contiene($digitos));
                }
            });
        });
    }

    /**
     * Filtro por estado.
     *
     * El valor vacío no filtra, y un valor desconocido tampoco: el listado
     * muestra todo en lugar de inventar un criterio. Igual el
     * ProveedorFiltroRequest lo valida contra la lista cerrada, así que el
     * usuario recibe el aviso en vez de un resultado silenciosamente distinto
     * al que pidió.
     */
    public function scopeConEstado(Builder $query, ?string $estado): Builder
    {
        return match ($estado) {
            'activos'   => $query->where('activo', true),
            'inactivos' => $query->where('activo', false),
            default     => $query,
        };
    }

    /**
     * Filtro por canal de pedido.
     *
     * Se compara contra las claves de CANALES y no con filled(): un canal
     * inexistente no filtra nada en lugar de devolver un listado vacío que
     * parecería decir "no tenés proveedores de ese tipo".
     */
    public function scopeConCanal(Builder $query, ?string $canal): Builder
    {
        return $query->when(
            array_key_exists((string) $canal, self::CANALES),
            fn (Builder $query) => $query->where('canal_pedido', $canal),
        );
    }
}