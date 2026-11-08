<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\FactorCosto;
use App\Models\HistorialPrecio;
use App\Models\Impuesto;
use App\Models\PoliticaPrecios;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Collection;
use App\Support\MotorPrecios;
use App\Models\LoteAjuste;
use Illuminate\Support\Facades\DB;

/**
 * El motor de precios conectado a la base: resuelve la cascada de ganancia,
 * arma el MotorPrecios con la parametrización vigente, y escribe el precio
 * dejando su asiento en el historial.

 * Métodos:
 *   calcular()      el desglose de un producto, sin guardar
 *   simular()       el desglose de un costo suelto, sin producto
 *   gananciaDe()    la cascada producto → categoría y sus padres → global
 *   recalcular()    recalcula y asienta; devuelve cuál de los cuatro casos fue
 *   fijarPrecios()  escribe dos precios dados y asienta el cambio, con su lote si lo tiene
 *   motor()         el MotorPrecios armado para una alícuota de IVA
 */
class PrecioService
{
    /** El producto tiene el precio fijado a mano: el motor no lo toca. */
    public const MANUAL = 'manual';

    /** Todavía no entró mercadería: sin costo no hay precio que calcular. */
    public const SIN_COSTO = 'sin_costo';

    /** El precio calculado es el que ya tenía: no se asienta un cambio que no hubo. */
    public const SIN_CAMBIO = 'sin_cambio';

    public const APLICADO = 'aplicado';

    /** El texto de cada resultado, para el mensaje de la pantalla. */
    public const RESULTADOS = [
        self::MANUAL     => 'Tiene el precio fijado a mano: quedó como estaba.',
        self::SIN_COSTO  => 'Todavía no tiene costo cargado: no hay precio que calcular.',
        self::SIN_CAMBIO => 'El precio calculado es el que ya tenía.',
        self::APLICADO   => 'Precio actualizado.',
    ];

    private ?PoliticaPrecios $politica = null;

    private ?array $factores = null;

    private ?array $generales = null;

    private ?array $catalogoIva = null;

    private ?MotorPrecios $redondeador = null;

    /** El desglose completo del producto, tal como está hoy. No guarda nada. */
    public function calcular(Producto $producto): array
    {
        return $this->motor($producto->alicuota_iva)
            ->calcular($producto->costo_promedio, $this->gananciaDe($producto));
    }

    /** Lo mismo para un costo que no es de ningún producto: es el simulador. */
    public function simular(float|string $costoNeto, float|string $ganancia, float|string $alicuotaIva): array
    {
        return $this->motor($alicuotaIva)->calcular($costoNeto, $ganancia);
    }

    /**
     * La ganancia que le toca al producto: la suya, la de su categoría o la de
     * alguna categoría de arriba, y si ninguna define, la global.
     */
    public function gananciaDe(Producto $producto): string
    {
        if ($producto->ganancia !== null) {
            return $producto->ganancia;
        }

        $producto->loadMissing('categoria.padre.padre');

        $categoria = $producto->categoria;
        $nivel     = 1;

        while ($categoria !== null && $nivel <= Categoria::PROFUNDIDAD_MAXIMA) {
            if ($categoria->ganancia !== null) {
                return $categoria->ganancia;
            }

            $categoria = $categoria->padre;
            $nivel++;
        }

        return $this->politica()->ganancia_global;
    }

    /**
     * Recalcula el precio del producto desde los factores.
     *
     * @return string  MANUAL, SIN_COSTO, SIN_CAMBIO o APLICADO
     */
    public function recalcular(Producto $producto, ?User $usuario = null, string $motivo = 'recalculo'): string
    {
        if ($producto->precio_manual) {
            return self::MANUAL;
        }

        // Con costo cero el cálculo daría cero, y un precio cero es regalar el
        // producto. No hay precio que afirmar hasta que entre mercadería.
        if ((float) $producto->costo_promedio <= 0) {
            return self::SIN_COSTO;
        }

        $calculo = $this->calcular($producto);

        return $this->fijarPrecios(
            $producto,
            $calculo['contado']['final'],
            $calculo['lista']['final'],
            $usuario,
            $motivo,
        );
    }

    /**
     * Escribe los dos precios y asienta el cambio.
     * @param  array<string, float|string|null>  $economia
     * @return string  SIN_CAMBIO o APLICADO
     */
    public function fijarPrecios(
        Producto $producto,
        float|string $contado,
        float|string $lista,
        ?User $usuario,
        string $motivo,
        ?LoteAjuste $lote = null,
        array $economia = [],
    ): string {
        $anteriorContado = $producto->precio_contado;
        $anteriorLista   = $producto->precio_lista;

        $mismoPrecio = (float) $anteriorContado === (float) $contado
            && (float) $anteriorLista === (float) $lista;

        if ($mismoPrecio && $economia === []) {
            return self::SIN_CAMBIO;
        }

        DB::transaction(function () use (
            $producto, $contado, $lista, $usuario, $motivo, $lote, $economia, $anteriorContado, $anteriorLista
        ) {
            $producto->precio_contado = $contado;
            $producto->precio_lista   = $lista;
            $producto->save();

            HistorialPrecio::create([
                'producto_id'             => $producto->id,
                'usuario_id'              => $usuario?->id,
                'lote_id'                 => $lote?->id,
                'motivo'                  => $motivo,
                'precio_contado_anterior' => $anteriorContado,
                'precio_contado_nuevo'    => $contado,
                'precio_lista_anterior'   => $anteriorLista,
                'precio_lista_nuevo'      => $lista,
                'costo_anterior'          => $economia['costo_anterior'] ?? null,
                'costo_nuevo'             => $economia['costo_nuevo'] ?? null,
                'ganancia_anterior'       => $economia['ganancia_anterior'] ?? null,
                'ganancia_nuevo'          => $economia['ganancia_nuevo'] ?? null,
            ]);
        });

        return self::APLICADO;
    }

    /** El motor armado con la parametrización vigente, para una alícuota de IVA. */
    public function motor(float|string $alicuotaIva): MotorPrecios
    {
        $politica = $this->politica();

        return new MotorPrecios(
            $this->factores(),
            $this->impuestosDe($alicuotaIva),
            $politica->recargo_lista,
            $politica->redondeo_multiplo,
            $politica->redondeo_modo,
        );
    }

    /**
     * El precio de lista que le corresponde a un precio de contado tipeado a mano.
     *
     */
    public function listaDesdeContado(float|string $contado): float
    {
        $recargo = 1 + ((float) $this->politica()->recargo_lista / 100);

        return $this->redondeador()->redondear((float) $contado * $recargo);
    }

    /**
     * El desglose de un importe que ya se cobró, con los impuestos vigentes para
     * esa alícuota. Es lo que la línea de venta congela.
     */
    public function desglosarImporte(float|string $total, float|string $alicuotaIva): array
    {
        return $this->motor($alicuotaIva)->descomponer($total);
    }

    /**
     * Asienta el precio con el que nace un producto.
     *
     * Los anteriores van en cero: antes del alta no había precio. Es lo que hace
     * que el historial se pueda leer completo desde la primera fila.
     */
    public function asentarAlta(Producto $producto, ?User $usuario): void
    {
        HistorialPrecio::create([
            'producto_id'             => $producto->id,
            'usuario_id'              => $usuario?->id,
            'motivo'                  => 'alta',
            'precio_contado_anterior' => 0,
            'precio_contado_nuevo'    => $producto->precio_contado,
            'precio_lista_anterior'   => 0,
            'precio_lista_nuevo'      => $producto->precio_lista,
        ]);
    }

    /** Un motor sin impuestos ni factores: lo único que sabe es redondear. */
    private function redondeador(): MotorPrecios
    {
        $politica = $this->politica();

        return $this->redondeador ??= new MotorPrecios(
            redondeoMultiplo: $politica->redondeo_multiplo,
            redondeoModo: $politica->redondeo_modo,
        );
    }

    /** El IVA del producto primero, y después los que alcanzan a todo el catálogo. */
    private function impuestosDe(float|string $alicuotaIva): array
    {
        $clave = number_format((float) $alicuotaIva, 2, '.', '');

        return [
            [
                // Si la alícuota se dio de baja del catálogo el producto sigue
                // teniéndola, y el desglose la nombra por su número en vez de
                // quedarse sin nombre.
                'nombre'      => $this->catalogoIva()[$clave] ?? "IVA {$clave} %",
                'alicuota'    => $clave,
                'comprobante' => 'iva',
            ],
            ...$this->generales(),
        ];
    }

    private function politica(): PoliticaPrecios
    {
        return $this->politica ??= PoliticaPrecios::vigente();
    }

    private function factores(): array
    {
        return $this->factores ??= FactorCosto::query()->activos()->ordenados()->get()
            ->map(fn (FactorCosto $factor) => [
                'nombre'     => $factor->nombre,
                'modo'       => $factor->modo,
                'porcentaje' => $factor->porcentaje,
            ])->all();
    }

    private function generales(): array
    {
        return $this->generales ??= Impuesto::query()->activos()->deAlcance('general')->ordenados()->get()
            ->map(fn (Impuesto $impuesto) => [
                'nombre'      => $impuesto->nombre,
                'alicuota'    => $impuesto->alicuota,
                'comprobante' => $impuesto->comprobante,
            ])->all();
    }

    /** Las alícuotas con su nombre, activas o no: un producto puede tener una dada de baja. */
    private function catalogoIva(): array
    {
        return $this->catalogoIva ??= Impuesto::query()->deAlcance('producto')->ordenados()
            ->pluck('nombre', 'alicuota')
            ->all();
    }

    // ------------------------------------------------------------------
    // Parametrización
    // ------------------------------------------------------------------

    public function actualizarPolitica(array $datos): PoliticaPrecios
    {
        $politica = PoliticaPrecios::vigente();

        return DB::transaction(function () use ($politica, $datos) {
            $politica->ganancia_global   = $datos['ganancia_global'];
            $politica->recargo_lista     = $datos['recargo_lista'];
            $politica->redondeo_multiplo = $datos['redondeo_multiplo'];
            $politica->redondeo_modo     = $datos['redondeo_modo'];
            $politica->save();

            return $politica;
        });
    }

    /** Alta y edición del impuesto, con los campos enumerados uno por uno. */
    public function guardarImpuesto(array $datos, ?Impuesto $impuesto = null): Impuesto
    {
        $impuesto ??= new Impuesto();

        return DB::transaction(function () use ($impuesto, $datos) {
            $impuesto->nombre      = $datos['nombre'];
            $impuesto->alicuota    = $datos['alicuota'];
            $impuesto->alcance     = $datos['alcance'];
            $impuesto->comprobante = $datos['comprobante'];
            $impuesto->orden       = $datos['orden'];
            $impuesto->activo      = $datos['activo'];
            $impuesto->save();

            return $impuesto;
        });
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminarImpuesto(Impuesto $impuesto): bool
    {
        if ($this->estaEnUso($impuesto)) {
            $impuesto->update(['activo' => false]);

            return false;
        }

        DB::transaction(fn () => $impuesto->delete());

        return true;
    }

    /**
     * Si hay productos con esa alícuota.
     *
     * Sólo tiene sentido para los de alcance 'producto': un impuesto general
     * no lo referencia ninguna fila, vive adentro del precio ya calculado, y
     * el desglose congelado del renglón guarda su nombre. Borrarlo no deja
     * nada colgando.
     */
    public function estaEnUso(Impuesto $impuesto): bool
    {
        return $impuesto->alcance === 'producto'
            && Producto::query()->where('alicuota_iva', $impuesto->alicuota)->exists();
    }

    /** Alta y edición del factor de costo. */
    public function guardarFactor(array $datos, ?FactorCosto $factor = null): FactorCosto
    {
        $factor ??= new FactorCosto();

        return DB::transaction(function () use ($factor, $datos) {
            $factor->nombre     = $datos['nombre'];
            $factor->modo       = $datos['modo'];
            $factor->porcentaje = $datos['porcentaje'];
            $factor->orden      = $datos['orden'];
            $factor->activo     = $datos['activo'];
            $factor->save();

            return $factor;
        });
    }

    /**
     * Baja física, y no lógica como la del impuesto.
     *
     * Un factor de costo no lo referencia ninguna fila: ni el producto, ni la
     * línea de venta, que congela impuestos y no factores porque los factores
     * no van en el comprobante. Los precios que ya lo tenían adentro siguen
     * como están hasta que se recalculen, y eso lo dice el mensaje.
     */
    public function eliminarFactor(FactorCosto $factor): void
    {
        DB::transaction(fn () => $factor->delete());
    }

    /**
     * Cuántos productos usan cada alícuota, para que la pantalla muestre qué
     * se rompe al dar una de baja. Lo cuenta la base, en una sola consulta (A-26).
     *
     * @return Collection<string, int>
     */
    public function productosPorAlicuota(): Collection
    {
        return Producto::query()
            ->selectRaw('alicuota_iva, COUNT(*) AS total')
            ->groupBy('alicuota_iva')
            ->pluck('total', 'alicuota_iva');
    }
}