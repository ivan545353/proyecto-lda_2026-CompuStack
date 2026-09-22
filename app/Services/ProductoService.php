<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Reglas de negocio de productos.
 *
 * Reemplaza a ItemService del original. Las validaciones de campo pasaron a
 * ProductoRequest; acá quedan las reglas que dependen del estado del sistema.
 *
 * Reglas propias:
 *
 *   1. Si el precio de contado viene vacío, es igual al de lista. La ayuda del
 *      formulario lo dice: no es un valor puesto en silencio.
 *   2. stock, stock_reservado y costo_promedio no se tocan nunca desde acá.
 *      Los mueve el StockService (Fase 5), dejando movimiento en el kardex.
 *   3. Las imágenes finales las describe un orden (ver
 *      ProductoRequest::ordenDeImagenes()): cuáles quedan, en qué orden y
 *      cuál es la principal. Las nuevas se guardan antes de la transacción
 *      y se borran si la base rechaza la operación; las que se quitan se
 *      borran después del commit, y la lista de qué borrar se calcula a
 *      partir de lo que el producto tiene, nunca de lo que envía el usuario.
 *   4. Un producto referenciado —en ventas, órdenes de compra o el kardex— o
 *      con stock distinto de cero no se borra.
 *
 * Métodos:
 *   crear()        alta, con imágenes opcionales
 *   actualizar()   edición, agregando y quitando imágenes
 *   eliminar()     baja física o lógica; devuelve cuál fue
 */
class ProductoService
{
    private const CARPETA = 'productos';

    private const REFERENCIAS = ['venta_lineas', 'orden_compra_lineas', 'movimientos_stock'];

     /**
     * @param  array<int, UploadedFile>  $imagenes
     * @param  array<int, array>|null    $orden  ver ProductoRequest::ordenDeImagenes()
     */
    public function crear(array $datos, array $imagenes = [], ?array $orden = null): Producto
    {
        $nuevas = $this->guardarNuevas($imagenes, $orden);

        try {
            return DB::transaction(fn () => Producto::create([
                ...$this->atributos($datos),
                'imagenes' => $this->componerImagenes([], $nuevas, $orden) ?: null,
            ]));
        } catch (Throwable $e) {
            $this->borrarArchivos(array_values($nuevas));

            throw $e;
        }
    }

    /**
     * @param  array<int, UploadedFile>  $imagenes
     * @param  array<int, array>|null    $orden  ver ProductoRequest::ordenDeImagenes()
     */
    public function actualizar(Producto $producto, array $datos, array $imagenes = [], ?array $orden = null): Producto
    {
        $actuales = $producto->imagenes ?? [];
        $nuevas   = $this->guardarNuevas($imagenes, $orden);
        $final    = $this->componerImagenes($actuales, $nuevas, $orden);

        try {
            $actualizado = DB::transaction(function () use ($producto, $datos, $final) {
                $producto->update([
                    ...$this->atributos($datos),
                    'imagenes' => $final ?: null,
                ]);

                return $producto->fresh();
            });
        } catch (Throwable $e) {
            $this->borrarArchivos(array_values($nuevas));

            throw $e;
        }

        // Se borran las que el producto TENÍA y no quedaron en la lista final.
        // La lista de archivos a borrar se calcula acá, a partir de lo que el
        // producto tiene: ningún dato enviado por el usuario llega nunca a
        // Storage::delete(), así que un "../../.env" no tiene por dónde entrar.
        // Y va después del commit, porque el disco no participa de la
        // transacción.
        $this->borrarArchivos(array_values(array_diff($actuales, $final)));

        return $actualizado;
    }

    /**
     * @return bool  true si se borró la fila, false si sólo se desactivó
     */
    public function eliminar(Producto $producto): bool
    {
        if ($this->debeConservarse($producto)) {
            $producto->update(['activo' => false]);

            return false;
        }

        $imagenes = $producto->imagenes ?? [];

        DB::transaction(fn () => $producto->delete());

        $this->borrarArchivos($imagenes);

        return true;
    }

    /**
     * Opciones de los selectores del formulario: las activas, más la que el
     * producto ya tiene aunque se haya desactivado.
     *
     * Mismo criterio que ProductoRequest::referenciaActiva() y que
     * CategoriaService::padresPosibles(): si la actual no apareciera, el
     * selector se abriría en otra opción y guardar sin tocar nada le
     * cambiaría la marca, la categoría o el proveedor al producto.
     *
     * @return array{categorias: Collection, marcas: Collection, proveedores: Collection}
     */
    public function opciones(?Producto $producto = null): array
    {
        return [
            'categorias' => $this->activasOActual(Categoria::query()->with('padre.padre'), $producto?->categoria_id)
                ->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'marcas'      => $this->activasOActual(Marca::query()->orderBy('nombre'), $producto?->marca_id),
            'proveedores' => $this->activasOActual(Proveedor::query()->orderBy('razon_social'), $producto?->proveedor_id),
        ];
    }

        /**
     * Guarda en disco sólo los archivos nuevos que el orden usa: un archivo
     * subido que no figura en la lista final no se guarda, así no queda
     * huérfano.
     *
     * @param  array<int, UploadedFile>  $archivos
     * @return array<int, string>  ruta guardada, por índice del archivo en la petición
     */
    private function guardarNuevas(array $archivos, ?array $orden): array
    {
        $usados = $orden === null
            ? array_keys($archivos)
            : array_column(array_filter($orden, fn (array $e) => $e['tipo'] === 'nueva'), 'indice');

        $rutas = [];

        foreach ($usados as $indice) {
            if (isset($archivos[$indice])) {
                $rutas[$indice] = $archivos[$indice]->store(self::CARPETA, 'public');
            }
        }

        return $rutas;
    }

    /**
     * La lista final de rutas.
     *
     * Sin orden: las actuales como estaban y las nuevas al final.
     * Con orden: exactamente lo que dice. La primera es la principal.
     *
     * @param  array<int, string>  $actuales
     * @param  array<int, string>  $nuevas  ruta por índice
     * @return array<int, string>
     */
    private function componerImagenes(array $actuales, array $nuevas, ?array $orden): array
    {
        if ($orden === null) {
            return [...$actuales, ...array_values($nuevas)];
        }

        $final = [];

        foreach ($orden as $elemento) {
            $ruta = match ($elemento['tipo']) {
                // Sólo rutas que el producto ya tiene. El Request lo valida;
                // esta es la segunda defensa, para que un orden armado a mano
                // no pueda hacer pasar un archivo ajeno por imagen del
                // producto.
                'actual' => in_array($elemento['ruta'], $actuales, true) ? $elemento['ruta'] : null,
                'nueva'  => $nuevas[$elemento['indice']] ?? null,
                default  => null,
            };

            if ($ruta !== null && ! in_array($ruta, $final, true)) {
                $final[] = $ruta;
            }
        }

        return $final;
    }

    private function activasOActual(Builder $query, ?int $actual): Collection
    {
        return $query
            ->where(fn ($query) => $query
                ->where('activo', true)
                ->when($actual, fn ($query, $id) => $query->orWhere('id', $id)))
            ->get();
    }

    private function debeConservarse(Producto $producto): bool
    {
        // Con stock distinto de cero hay mercadería en el depósito (o un
        // faltante que explicar). Borrar el producto haría desaparecer del
        // sistema algo que sigue existiendo en el mundo.
        if ($producto->stock !== 0 || $producto->stock_reservado !== 0) {
            return true;
        }

        foreach (self::REFERENCIAS as $tabla) {
            if (DB::table($tabla)->where('producto_id', $producto->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las columnas que el formulario puede escribir, una por una.
     *
     * No se pasa $datos entero al modelo: validated() trae 'imagenes' con los
     * ARCHIVOS subidos, y 'imagenes' también es una columna asignable. Un
     * create($request->validated()) intentaría guardar objetos UploadedFile
     * en el JSON. Enumerar los campos además deja fuera, por construcción,
     * todo lo que no está en esta lista.
     */
    private function atributos(array $datos): array
    {
        return [
            'codigo'              => $datos['codigo'],
            'nombre'              => $datos['nombre'],
            'descripcion'         => $datos['descripcion'] ?? null,
            'categoria_id'        => $datos['categoria_id'],
            'marca_id'            => $datos['marca_id'] ?? null,
            'proveedor_id'        => $datos['proveedor_id'] ?? null,
            'precio_lista'        => $datos['precio_lista'],
            'precio_contado'      => $datos['precio_contado'] ?? $datos['precio_lista'],
            'alicuota_iva'        => $datos['alicuota_iva'],
            'stock_minimo'        => $datos['stock_minimo'],
            'cantidad_reposicion' => $datos['cantidad_reposicion'],
            'activo'              => $datos['activo'],
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $archivos
     * @return array<int, string>
     */
    private function guardarImagenes(array $archivos): array
    {
        return array_map(
            fn (UploadedFile $archivo) => $archivo->store(self::CARPETA, 'public'),
            $archivos,
        );
    }

    /** @param  array<int, string>  $rutas */
    private function borrarArchivos(array $rutas): void
    {
        if ($rutas !== []) {
            Storage::disk('public')->delete($rutas);
        }
    }
}