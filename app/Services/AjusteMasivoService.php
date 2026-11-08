<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Categoria;
use App\Models\HistorialPrecio;
use App\Models\LoteAjuste;
use App\Models\Marca;
use App\Models\PoliticaPrecios;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Importe;
use App\Support\MotorPrecios;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El ajuste masivo de precios: un aumento de rubro en lugar de producto por producto.
 *
 * No calcula ningún precio: se lo pide a `PrecioService`, que a su vez usa
 * `MotorPrecios`. Así la previsualización, el ajuste y el recálculo de un producto
 * suelto dan el mismo número, que es la condición para que la previsualización sea una
 * salvaguarda y no una estimación.
 *
 * **Las tres salvaguardas, y dónde vive cada una:**
 *   1. Previsualización obligatoria → `previsualizar()` y `proyectar()`, que no escriben
 *      nada. La pantalla no ofrece aplicar sin haber mostrado los conteos.
 *   2. Lote reversible → `aplicar()` escribe un `LoteAjuste` y cuelga de él cada
 *      asiento; `deshacer()` escribe otro lote que devuelve los precios.
 *   3. Respeta el precio manual → el recorte excluye a los marcados salvo que la
 *      casilla pida incluirlos, y `previsualizar()` informa cuántos quedaron afuera.
 *
 *
 * Métodos:
 *   consulta()       el recorte, como consulta encadenable
 *   previsualizar()  los conteos del conjunto completo, sin escribir nada
 *   proyectar()      qué le pasaría a un producto; lo usan la tabla y el ajuste
 *   aplicar()        escribe el ajuste y devuelve el lote, o null si no cambió nada
 *   deshacer()       devuelve los precios escribiendo otro lote
 *   criterioTexto()  el recorte escrito, para congelarlo en el lote
 */
class AjusteMasivoService
{
    /** Cuántos productos trae cada tanda. */
    private const TANDA = 500;

    private ?PoliticaPrecios $politica = null;

    public function __construct(private PrecioService $precios, private StockService $stock)
    {
    }

    /**
     * El conjunto al que alcanza el ajuste.
     *
     * Devuelve la consulta y no los productos: la pantalla la pagina y las dos pasadas
     * del servicio la recorren en tandas, y ninguna de las tres quiere veinte mil
     * modelos en memoria.
     */
    public function consulta(array $datos): Builder
    {
        // Con alcance «todos» no hay recorte que aplicar. El Form Request rechaza que
        // vengan los dos: pedir todo el catálogo y además filtrarlo es una
        // contradicción que se le devuelve al usuario, no se resuelve en silencio.
        $recorte = ($datos['alcance'] ?? 'recorte') === 'todos' ? [] : $datos;

        return Producto::query()
            // La cascada de ganancia sube hasta tres niveles de categoría; sin esto,
            // cada producto de la tanda costaría sus propias consultas.
            ->with('categoria.padre.padre')
            ->deCategoria($recorte['categoria_id'] ?? null)
            ->deMarca($recorte['marca_id'] ?? null)
            ->deProveedor($recorte['proveedor_id'] ?? null)
            ->conIds($recorte['ids'] ?? null)
            ->conCostoDesde($recorte['costo_desde'] ?? null)
            ->conCostoHasta($recorte['costo_hasta'] ?? null)
            ->conPrecioDesde($recorte['precio_desde'] ?? null)
            ->conPrecioHasta($recorte['precio_hasta'] ?? null)
            ->conPrecioManual($this->incluyeManuales($datos) ? null : false);
    }

    /**
     * Los números que la pantalla muestra antes de que se escriba nada.
     *
     * Una sola pasada por el conjunto **completo**, no por la página que se muestra: el
     * operador necesita saber a cuántos alcanza el ajuste de verdad, y «3.000» no se
     * puede deducir de las veinte filas de la primera página.
     *
     * @return array{total: int, alcanzados: int, sin_cambio: int, sin_costo: int,
     *               manuales_afuera: int, bajo_costo: int}
     */
    public function previsualizar(array $datos): array
    {
        $conteos = [
            'total'           => 0,
            'alcanzados'      => 0,
            'sin_cambio'      => 0,
            'sin_costo'       => 0,
            'manuales_afuera' => $this->manualesAfuera($datos),
            'bajo_costo'      => 0,
        ];

        $this->consulta($datos)->chunkById(self::TANDA, function ($productos) use ($datos, &$conteos) {
            foreach ($productos as $producto) {
                $proyeccion = $this->proyectar($producto, $datos);

                $conteos['total']++;

                $conteos[match ($proyeccion['resultado']) {
                    PrecioService::SIN_COSTO  => 'sin_costo',
                    PrecioService::SIN_CAMBIO => 'sin_cambio',
                    default                   => 'alcanzados',
                }]++;

                if ($proyeccion['bajo_costo']) {
                    $conteos['bajo_costo']++;
                }
            }
        });

        return $conteos;
    }

    /**
     * Qué le pasaría a este producto si el ajuste se aplicara. No escribe nada.
     *
     * Es la misma cuenta que hace `aplicar()`, y eso no es una coincidencia que haya
     * que mantener a mano: `aplicar()` llama a este método. Si la previsualización
     * calculara por su lado, la pantalla podría prometer un precio y el ajuste escribir
     * otro.
     *
     * `costo` y `ganancia` en null significan «esta operación no las toca», que es
     * distinto de que no cambien.
     *
     * @return array{resultado: string, contado: float, lista: float, costo: ?float,
     *               ganancia: ?string, bajo_costo: bool}
     */
    public function proyectar(Producto $producto, array $datos): array
    {
        $operacion = $datos['operacion'];
        $costo     = (float) $producto->costo_promedio;

        // Las tres operaciones que parten del costo no pueden hacer nada sin costo:
        // calcularían cero, y un precio cero es regalar el producto. Es la misma razón
        // por la que PrecioService::recalcular() devuelve SIN_COSTO.
        if ($costo <= 0 && in_array($operacion, ['costo', 'ganancia', 'recalculo'], true)) {
            return $this->proyeccionQuieta($producto, PrecioService::SIN_COSTO);
        }

        $costoNuevo    = $operacion === 'costo' ? round($costo * $this->factor($datos), 2) : null;
        $gananciaNueva = $operacion === 'ganancia' ? (string) $datos['ganancia'] : null;

        [$contado, $neto] = match ($operacion) {
            'precio'   => $this->desdeElPrecio((float) $producto->precio_contado * $this->factor($datos), $producto),
            'redondeo' => $this->desdeElPrecio((float) $producto->precio_contado, $producto, $datos['redondeo_multiplo']),
            default    => $this->desdeElCosto(
                $costoNuevo ?? $costo,
                $gananciaNueva ?? $this->precios->gananciaDe($producto),
                $producto,
            ),
        };

        $lista = $this->precios->listaDesdeContado($contado);

        $cambiaElPrecio = (float) $producto->precio_contado !== $contado
            || (float) $producto->precio_lista !== $lista;

        $cambiaElCosto = $costoNuevo !== null && $costoNuevo !== $costo;

        $cambiaLaGanancia = $gananciaNueva !== null
            && ($producto->ganancia === null || (float) $producto->ganancia !== (float) $gananciaNueva);

        if (! $cambiaElPrecio && ! $cambiaElCosto && ! $cambiaLaGanancia) {
            return $this->proyeccionQuieta($producto, PrecioService::SIN_CAMBIO);
        }

        $costoDeReferencia = $costoNuevo ?? $costo;

        return [
            'resultado'  => PrecioService::APLICADO,
            'contado'    => $contado,
            'lista'      => $lista,
            'costo'      => $cambiaElCosto ? $costoNuevo : null,
            'ganancia'   => $cambiaLaGanancia ? $gananciaNueva : null,
            // El neto es lo que le queda al comercio antes de los impuestos: si es
            // menor que el costo, ese producto se vendería perdiendo plata. Es la
            // alerta que el plan pide en la previsualización.
            'bajo_costo' => $costoDeReferencia > 0 && $neto < $costoDeReferencia,
        ];
    }

    /**
     * Aplica el ajuste. Devuelve el lote, o null si no hubo nada que cambiar.
     *
     */
    public function aplicar(array $datos, ?User $usuario): ?LoteAjuste
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $lote = LoteAjuste::create([
                'usuario_id'        => $usuario?->id,
                'operacion'         => $datos['operacion'],
                ...$this->parametrosDe($datos),
                'criterio'          => $this->criterio($datos),
                'criterio_texto'    => $this->criterioTexto($datos),
                'alcanzados'        => 0,
                'omitidos'          => 0,
            ]);

            $alcanzados = 0;
            $omitidos   = 0;

            $this->consulta($datos)->chunkById(
                self::TANDA,
                function ($productos) use ($datos, $lote, $usuario, &$alcanzados, &$omitidos) {
                    foreach ($productos as $producto) {
                        if ($this->escribir($producto, $datos, $lote, $usuario)) {
                            $alcanzados++;
                        } else {
                            $omitidos++;
                        }
                    }
                },
            );

            if ($alcanzados === 0) {
                $lote->delete();

                return null;
            }

            $lote->update(['alcanzados' => $alcanzados, 'omitidos' => $omitidos]);

            return $lote;
        });
    }

    /**
     * Deshace un lote escribiendo otro. Devuelve la reversión, o null si no devolvió nada.
     *
     * @throws ReglaDeNegocioException
     */
    public function deshacer(LoteAjuste $lote, ?User $usuario): ?LoteAjuste
    {
        $ultimo = LoteAjuste::ultimoReversible();

        if ($ultimo === null || $ultimo->id !== $lote->id) {
            throw new ReglaDeNegocioException(
                'Sólo se puede deshacer el último ajuste. Los precios anteriores que guarda el '
                .'historial valen mientras no haya pasado nada después.'
            );
        }

        return DB::transaction(function () use ($lote, $usuario) {
            $reversion = LoteAjuste::create([
                'usuario_id'     => $usuario?->id,
                'operacion'      => 'deshacer',
                'criterio'       => ['revierte' => $lote->id],
                'criterio_texto' => mb_substr(
                    'Deshace el ajuste del '.$lote->created_at->format('d/m/Y H:i').': '.$lote->criterio_texto,
                    0,
                    255,
                ),
                'alcanzados' => 0,
                'omitidos'   => 0,
                'revierte_a' => $lote->id,
            ]);

            $devueltos = 0;
            $quietos   = 0;

            $lote->cambios()->with('producto')->chunkById(
                self::TANDA,
                function ($cambios) use ($lote, $reversion, $usuario, &$devueltos, &$quietos) {
                    foreach ($cambios as $cambio) {
                        if ($this->devolver($cambio, $lote, $reversion, $usuario)) {
                            $devueltos++;
                        } else {
                            $quietos++;
                        }
                    }
                },
            );

            if ($devueltos === 0) {
                $reversion->delete();

                return null;
            }

            $reversion->update(['alcanzados' => $devueltos, 'omitidos' => $quietos]);

            return $reversion;
        });
    }

    /**
     * El recorte escrito, para congelarlo en el lote.
     *
     * Se guarda como texto porque los nombres cambian: una categoría se renombra, un
     * proveedor se da de baja, y el lote del mes pasado tiene que seguir diciendo sobre
     * qué corrió. Es el mismo motivo por el que `venta_lineas` congela su precio.
     */
    public function criterioTexto(array $datos): string
    {
        $partes = [];

        if (($datos['alcance'] ?? 'recorte') === 'todos') {
            $partes[] = 'Todos los productos';
        } else {
            if (filled($datos['categoria_id'] ?? null)) {
                $categoria = Categoria::find($datos['categoria_id']);
                $partes[]  = 'Categoría '.($categoria?->nombre ?? 'eliminada').' y sus subcategorías';
            }

            if (filled($datos['marca_id'] ?? null)) {
                $partes[] = 'Marca '.(Marca::find($datos['marca_id'])?->nombre ?? 'eliminada');
            }

            if (filled($datos['proveedor_id'] ?? null)) {
                $partes[] = 'Proveedor '.(Proveedor::find($datos['proveedor_id'])?->razon_social ?? 'eliminado');
            }

            if (filled($datos['ids'] ?? null)) {
                $cuantos  = count($datos['ids']);
                $partes[] = $cuantos.($cuantos === 1 ? ' producto elegido a mano' : ' productos elegidos a mano');
            }

            foreach (['costo' => 'Costo', 'precio' => 'Precio'] as $campo => $rotulo) {
                $desde = $datos["{$campo}_desde"] ?? null;
                $hasta = $datos["{$campo}_hasta"] ?? null;

                if (filled($desde) && filled($hasta)) {
                    $partes[] = "{$rotulo} entre ".Importe::pesos($desde).' y '.Importe::pesos($hasta);
                } elseif (filled($desde)) {
                    $partes[] = "{$rotulo} desde ".Importe::pesos($desde);
                } elseif (filled($hasta)) {
                    $partes[] = "{$rotulo} hasta ".Importe::pesos($hasta);
                }
            }
        }

        if ($this->incluyeManuales($datos)) {
            $partes[] = 'incluyendo los de precio fijado a mano';
        }

        return mb_substr(implode(' · ', $partes), 0, 255);
    }

    // ------------------------------------------------------------------
    // La escritura, producto por producto
    // ------------------------------------------------------------------

    /** Escribe el ajuste de un producto. Devuelve si le cambió algo. */
    private function escribir(Producto $producto, array $datos, LoteAjuste $lote, ?User $usuario): bool
    {
        $proyeccion = $this->proyectar($producto, $datos);

        if ($proyeccion['resultado'] !== PrecioService::APLICADO) {
            return false;
        }

        $economia = [];

        if ($proyeccion['costo'] !== null) {
            $economia['costo_anterior'] = $producto->costo_promedio;
            // El costo lo escribe StockService, que es la única puerta a esa columna, y
            // devuelve el valor como quedó guardado: ese es el que se asienta.
            $economia['costo_nuevo'] = $this->stock->fijarCosto($producto, $proyeccion['costo']);
        }

        if ($proyeccion['ganancia'] !== null) {
            $economia['ganancia_anterior'] = $producto->ganancia;
            $economia['ganancia_nuevo']    = $proyeccion['ganancia'];

            $producto->ganancia = $proyeccion['ganancia'];
            $producto->save();
        }

        $this->precios->fijarPrecios(
            $producto,
            $proyeccion['contado'],
            $proyeccion['lista'],
            $usuario,
            'ajuste_masivo',
            $lote,
            $economia,
        );

        return true;
    }

    /** Devuelve un producto a lo que era antes del lote. Devuelve si lo hizo. */
    private function devolver(
        HistorialPrecio $cambio,
        LoteAjuste $lote,
        LoteAjuste $reversion,
        ?User $usuario,
    ): bool {
        $producto = $cambio->producto;

        // Si el precio de hoy no es el que dejó el lote, alguien lo movió después y
        // devolverlo pisaría ese hecho posterior.
        if ((float) $producto->precio_contado !== (float) $cambio->precio_contado_nuevo
            || (float) $producto->precio_lista !== (float) $cambio->precio_lista_nuevo) {
            return false;
        }

        $economia = [];

        // Qué se devuelve además del precio lo dice la operación del lote, no estas
        // columnas: `ganancia_anterior` en null es un valor legítimo —«heredá de la
        // categoría»— y no «el lote no tocó la ganancia».
        if ($lote->operacion === 'costo' && $cambio->costo_anterior !== null) {
            $economia['costo_anterior'] = $producto->costo_promedio;
            $economia['costo_nuevo']    = $this->stock->fijarCosto($producto, $cambio->costo_anterior);
        }

        if ($lote->operacion === 'ganancia') {
            $economia['ganancia_anterior'] = $producto->ganancia;
            $economia['ganancia_nuevo']    = $cambio->ganancia_anterior;

            $producto->ganancia = $cambio->ganancia_anterior;
            $producto->save();
        }

        $this->precios->fijarPrecios(
            $producto,
            $cambio->precio_contado_anterior,
            $cambio->precio_lista_anterior,
            $usuario,
            'deshacer',
            $reversion,
            $economia,
        );

        return true;
    }

    // ------------------------------------------------------------------
    // El cálculo, delegado
    // ------------------------------------------------------------------

    /**
     * El precio que sale de un importe ya formado, redondeado, con su neto.
     *
     * Con `$multiplo` redondea a ese múltiplo —es la operación «redondear a múltiplo
     * de X»— y sin él, al de la política. El **modo** sale siempre de la política: el
     * múltiplo lo elige la operación, pero hacia dónde se redondea es una decisión del
     * comercio y no cambia según quién llame.
     *
     * @return array{0: float, 1: float}  el precio final y su neto
     */
    private function desdeElPrecio(float $bruto, Producto $producto, float|string|null $multiplo = null): array
    {
        $contado = $multiplo === null
            ? $this->precios->motor($producto->alicuota_iva)->redondear($bruto)
            : $this->redondeador($multiplo)->redondear($bruto);

        return [$contado, $this->precios->desglosarImporte($contado, $producto->alicuota_iva)['neto']];
    }

    /**
     * El precio que sale del costo y la ganancia, por el motor.
     *
     * @return array{0: float, 1: float}  el precio final y su neto
     */
    private function desdeElCosto(float|string $costo, float|string $ganancia, Producto $producto): array
    {
        $contado = $this->precios->simular($costo, $ganancia, $producto->alicuota_iva)['contado'];

        return [$contado['final'], $contado['neto']];
    }

    /** Un motor que sólo sabe redondear, al múltiplo de la operación. */
    private function redondeador(float|string $multiplo): MotorPrecios
    {
        return new MotorPrecios(
            redondeoMultiplo: $multiplo,
            redondeoModo: $this->politica()->redondeo_modo,
        );
    }

    /** Por cuánto multiplica el porcentaje. Con -10 % da 0,9. */
    private function factor(array $datos): float
    {
        return 1 + ((float) $datos['porcentaje'] / 100);
    }

    /** La proyección de un producto al que el ajuste no le cambia nada. */
    private function proyeccionQuieta(Producto $producto, string $resultado): array
    {
        return [
            'resultado'  => $resultado,
            'contado'    => (float) $producto->precio_contado,
            'lista'      => (float) $producto->precio_lista,
            'costo'      => null,
            'ganancia'   => null,
            'bajo_costo' => false,
        ];
    }

    // ------------------------------------------------------------------
    // El criterio
    // ------------------------------------------------------------------

    /** El recorte tal como se pidió, para poder releer con qué se corrió el ajuste. */
    private function criterio(array $datos): array
    {
        $claves = [
            'alcance', 'categoria_id', 'marca_id', 'proveedor_id', 'ids',
            'costo_desde', 'costo_hasta', 'precio_desde', 'precio_hasta',
        ];

        $criterio = array_filter(
            array_intersect_key($datos, array_flip($claves)),
            fn ($valor) => filled($valor),
        );

        $criterio['incluir_manuales'] = $this->incluyeManuales($datos);

        return $criterio;
    }

    /**
     * El parámetro que la operación usa, y sólo ése.
     *
     * La pantalla puede mandar los tres —sin JavaScript los tres campos viajan— y
     * guardar un porcentaje en un lote de ganancia dejaría escrito un número que no
     * participó del cálculo. El lote es el hecho al que se vuelve para deshacer: lo que
     * dice tiene que ser lo que pasó.
     *
     * @return array{porcentaje: float|string|null, ganancia: float|string|null, redondeo_multiplo: float|string|null}
     */
    private function parametrosDe(array $datos): array
    {
        return [
            'porcentaje' => in_array($datos['operacion'], ['costo', 'precio'], true)
                ? ($datos['porcentaje'] ?? null)
                : null,

            'ganancia' => $datos['operacion'] === 'ganancia'
                ? ($datos['ganancia'] ?? null)
                : null,

            'redondeo_multiplo' => $datos['operacion'] === 'redondeo'
                ? ($datos['redondeo_multiplo'] ?? null)
                : null,
        ];
    }

    /**
     * Cuántos productos del recorte quedan afuera por tener el precio fijado a mano.
     */
    private function manualesAfuera(array $datos): int
    {
        if ($this->incluyeManuales($datos)) {
            return 0;
        }

        return $this->consulta(array_merge($datos, ['incluir_manuales' => true]))
            ->conPrecioManual(true)
            ->count();
    }

    private function incluyeManuales(array $datos): bool
    {
        return (bool) ($datos['incluir_manuales'] ?? false);
    }

    private function politica(): PoliticaPrecios
    {
        return $this->politica ??= PoliticaPrecios::vigente();
    }
}