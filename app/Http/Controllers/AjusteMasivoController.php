<?php

namespace App\Http\Controllers;

use App\Http\Requests\AjusteMasivoRequest;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Proveedor;
use App\Models\LoteAjuste;
use App\Services\AjusteMasivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * El ajuste masivo de precios: la pantalla, su previsualización y su aplicación.
 *
 * Métodos:
 *   index()    el formulario y, si vinieron parámetros, la previsualización paginada
 *   aplicar()  escribe el ajuste y vuelve con el resultado
 */
class AjusteMasivoController extends Controller
{
    /** Cuántas filas de la previsualización se muestran por página. */
    private const POR_PAGINA = 20;

    public function __construct(private AjusteMasivoService $service)
    {
    }

    public function index(AjusteMasivoRequest $request): View
    {
        $datos = $request->validated();

        // Sin el campo oculto la pantalla se está abriendo por primera vez: se dibuja
        // el formulario vacío y no se previsualiza nada. Mismo criterio que el
        // simulador, que por eso se ve vacío en lugar de roto.
        $previsualiza = $request->boolean('previsualizar') && $datos !== [];

        return view('precios.ajuste', [
            'datos'   => $datos,
            'conteos' => $previsualiza ? $this->service->previsualizar($datos) : null,
            'filas'   => $previsualiza ? $this->filas($datos) : null,
            ...$this->opciones(),
        ]);
    }

    public function aplicar(AjusteMasivoRequest $request): RedirectResponse
    {
        $lote = $this->service->aplicar($request->validated(), $request->user());

        // `aviso` y no `exito`: la operación corrió y no hizo falta hacer nada. Un
        // verde diría que pasó algo que no pasó.
        if ($lote === null) {
            return redirect()->route('precios.ajuste')->with('aviso',
                'El ajuste corrió y no cambió ningún precio: no hubo productos a los que '
                .'aplicarlo. Revisá el recorte, y si los que buscabas tienen el precio fijado '
                .'a mano, marcá la casilla para incluirlos.');
        }

        $mensaje = "Ajuste aplicado a {$lote->alcanzados} producto(s). Se puede deshacer.";

        if ($lote->omitidos > 0) {
            $mensaje .= " Quedaron {$lote->omitidos} sin cambios, por no tener costo cargado o "
                .'porque el precio nuevo era el que ya tenían.';
        }

                return redirect()->route('precios.lotes')->with('exito', $mensaje);
    }

    /**
     * El historial de ajustes: qué se hizo, cuándo, sobre qué conjunto y a cuántos
     * alcanzó.
     *
     * Sin filtros, y es una decisión: un historial de ajustes se lee del más nuevo al
     * más viejo y lo que se busca es el último, que es además el único que se puede
     * deshacer. Filtrarlo por operación o por usuario sería un informe, y los informes
     * van en los módulos con sus filtros puestos.
     *
     * `reversion` y `revertido` vienen cargados porque cada fila los mira: sin eso,
     * veinte filas serían cuarenta consultas.
     */
    public function lotes(): View
    {
        return view('precios.lotes', [
            'lotes' => LoteAjuste::query()
                ->with(['usuario', 'reversion', 'revertido'])
                ->recientes()
                ->paginate(20)
                ->withQueryString(),

            // El único que se puede deshacer. La pantalla ofrece el botón en esa fila y
            // en ninguna otra: el servicio rechaza el resto, y ofrecer un camino que el
            // servidor va a negar es el defecto que la sección 1 de
            // `pendientes-usabilidad.md` describe.
            'reversible' => LoteAjuste::ultimoReversible(),
        ]);
    }

    /**
     * Deshace un ajuste escribiendo otro.
     *
     * Si el lote no es el último reversible, el servicio lanza
     * `ReglaDeNegocioException` y `bootstrap/app.php` la traduce en un mensaje: no hace
     * falta un try/catch acá.
     */
    public function deshacer(LoteAjuste $lote): RedirectResponse
    {
        $reversion = $this->service->deshacer($lote, request()->user());

        if ($reversion === null) {
            return redirect()->route('precios.lotes')->with('aviso',
                'No se devolvió ningún precio: todos los productos de ese ajuste cambiaron de '
                .'precio después, y devolverlos pisaría esos cambios. El ajuste sigue estando y '
                .'se puede volver a intentar.');
        }

        $mensaje = "Se devolvieron los precios de {$reversion->alcanzados} producto(s).";

        if ($reversion->omitidos > 0) {
            $mensaje .= " Quedaron {$reversion->omitidos} sin devolver, porque su precio cambió "
                .'después del ajuste y devolverlo pisaría ese cambio.';
        }

        return redirect()->route('precios.lotes')->with('exito', $mensaje);
    }


    /**
     * La página de la tabla «precio actual → precio nuevo».
     *
     */
    private function filas(array $datos): LengthAwarePaginator
    {
        return $this->service->consulta($datos)
            ->orderBy('nombre')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn ($producto) => [
                'producto'   => $producto,
                'proyeccion' => $this->service->proyectar($producto, $datos),
            ]);
    }

    /**
     * Las listas de los selectores del recorte.
     *
     * Son las tres de `ProductoController::index()`, y siguen siendo locales: son
     * listas cortas —categorías, marcas, proveedores— y el criterio de
     * `selects-buscables.js` las deja del lado del cliente. Lo que no entra acá es un
     * selector de productos: ése crecería con el catálogo, y es del tramo E.
     */
    private function opciones(): array
    {
        return [
            'categorias'  => Categoria::with('padre.padre')->get()->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE),
            'marcas'      => Marca::orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'proveedores' => Proveedor::orderBy('razon_social')->get(['id', 'razon_social', 'activo']),
        ];
    }
}