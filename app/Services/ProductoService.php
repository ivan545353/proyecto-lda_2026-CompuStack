<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Categoria;
use App\Models\Impuesto;
use App\Models\User;
use App\Services\PrecioService;
use App\Models\Marca;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Reglas de negocio de productos.
 *
 * Reemplaza a ItemService del original. Las validaciones de campo pasaron a
 * ProductoRequest; acá quedan las reglas que dependen del estado del sistema.
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

    public function __construct(private PrecioService $precios){
    }

    /**
     * @param  array<int, UploadedFile>  $imagenes
     * @param  array<int, array>|null    $orden  ver ProductoRequest::ordenDeImagenes()
     */
       /**
     * @param  array<int, UploadedFile>  $imagenes
     * @param  array<int, array>|null    $orden  ver ProductoRequest::ordenDeImagenes()
     */
    public function crear(array $datos, array $imagenes = [], ?array $orden = null, ?User $usuario = null): Producto
    {
        $nuevas = $this->guardarNuevas($imagenes, $orden);

        try {
            return DB::transaction(function () use ($datos, $nuevas, $orden, $usuario) {
                // En el alta el precio siempre viene tipeado: el producto todavía
                // no tiene costo, así que el motor no tiene con qué calcularlo.
                $contado = $datos['precio_contado'];

                $producto = Producto::create([
                    ...$this->atributos($datos),
                    'precio_contado' => $contado,
                    'precio_lista'   => $this->precios->listaDesdeContado($contado),
                    'imagenes'       => $this->componerImagenes([], $nuevas, $orden) ?: null,
                ]);

                $this->precios->asentarAlta($producto, $usuario);

                return $producto;
            });
        } catch (Throwable $e) {
            $this->borrarArchivos(array_values($nuevas));

            throw $e;
        }
    }

    /**
     * @param  array<int, UploadedFile>  $imagenes
     * @param  array<int, array>|null    $orden  ver ProductoRequest::ordenDeImagenes()
     */
    public function actualizar(Producto $producto, array $datos, array $imagenes = [], ?array $orden = null, ?User $usuario = null): Producto
    {
        $actuales = $producto->imagenes ?? [];
        $nuevas   = $this->guardarNuevas($imagenes, $orden);
        $final    = $this->componerImagenes($actuales, $nuevas, $orden);

        try {
            $actualizado = DB::transaction(function () use ($producto, $datos, $final, $usuario) {
                $producto->update([
                    ...$this->atributos($datos),
                    'imagenes' => $final ?: null,
                ]);

                // Un precio tipeado es un cambio de precio y deja su fila en el
                // historial. Sin precio manual el número es del motor: no se toca
                // acá, se recalcula desde la ficha o desde el ajuste masivo.
                if ($producto->precio_manual && isset($datos['precio_contado'])) {
                    $this->precios->fijarPrecios(
                        $producto,
                        $datos['precio_contado'],
                        $this->precios->listaDesdeContado($datos['precio_contado']),
                        $usuario,
                        'manual',
                    );
                }

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
     * @return array{categorias: Collection, marcas: Collection, alicuotas: array<string, string>}
     */
    public function opciones(?Producto $producto = null): array
    {
        return [
            'categorias' => $this->activasOActual(Categoria::query()->with('padre.padre'), $producto?->categoria_id)
                ->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'marcas'    => $this->activasOActual(Marca::query()->orderBy('nombre'), $producto?->marca_id),
            'alicuotas' => $this->alicuotasPara($producto),
        ];
    }

    /** @return array<string, string> */
    private function alicuotasPara(?Producto $producto): array
    {
        $opciones = Impuesto::opcionesDeAlicuota();
        $actual   = $producto?->alicuota_iva;

        if ($actual !== null && ! array_key_exists($actual, $opciones)) {
            $opciones[$actual] = "IVA {$actual} % (dada de baja)";
        }

        return $opciones;
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
     */
    private function atributos(array $datos): array
    {
        return [
            'codigo'              => $datos['codigo'],
            'nombre'              => $datos['nombre'],
            'descripcion'         => $datos['descripcion'] ?? null,
            'categoria_id'        => $datos['categoria_id'],
            'marca_id'            => $datos['marca_id'] ?? null,
            'alicuota_iva'        => $datos['alicuota_iva'],
            'ganancia'            => $datos['ganancia'] ?? null,
            'precio_manual'       => $datos['precio_manual'],
            'stock_minimo'        => $datos['stock_minimo'],
            'cantidad_reposicion' => $datos['cantidad_reposicion'],
            'activo'              => $datos['activo'],
            // Sólo si viene: un llamador que no lo conoce no debe apagar un destacado sin querer.
            ...(array_key_exists('destacado', $datos) ? ['destacado' => (bool) $datos['destacado']] : []),
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