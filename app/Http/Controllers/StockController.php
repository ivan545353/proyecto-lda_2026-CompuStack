<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ImprimeListados;
use App\Http\Requests\AjusteStockRequest;
use App\Http\Requests\MovimientoFiltroRequest;
use App\Impresion\ListadoMovimientos;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kardex y ajuste de inventario. 
 *
 * Métodos:
 *   index()         el kardex, filtrado y paginado
 *   imprimir()      el mismo kardex filtrado, en PDF o en CSV
 *   crearAjuste()   formulario de ajuste de inventario de un producto
 *   guardarAjuste() registra el ajuste
 */
class StockController extends Controller
{
    use ImprimeListados;
    public function __construct(private StockService $service)
    {
    }

    public function index(MovimientoFiltroRequest $request): View
    {
        $movimientos = $this->filtradas($request)
            ->with(['producto:id,codigo,nombre', 'usuario:id,nombre,apellido'])
            ->paginate(15)
            ->withQueryString();

        return view('stock.index', [
            'movimientos' => $movimientos,
            'producto' => $request->filled('producto_id')
                ? Producto::find($request->integer('producto_id'))
                : null,

            'usuarios' => User::query()
                ->whereIn('id', MovimientoStock::query()->whereNotNull('usuario_id')->select('usuario_id'))
                ->orderBy('apellido')->orderBy('nombre')
                ->get(['id', 'nombre', 'apellido']),
        ]);
    }

    
    public function imprimir(MovimientoFiltroRequest $request, ListadoMovimientos $listado): Response|StreamedResponse|RedirectResponse
    {
        return $this->imprimirListado($request, $listado, $this->filtradas($request), 'stock.index');
    }

    public function crearAjuste(Producto $producto): View
    {
        return view('stock.ajuste', compact('producto'));
    }

    public function guardarAjuste(AjusteStockRequest $request, Producto $producto): RedirectResponse
    {
        $datos = $request->validated();

        $movimiento = $this->service->ajustar(
            $producto,
            (int) $datos['stock_contado'],
            $datos['motivo'],
            $request->user(),
            (int) $datos['stock_esperado'],
        );

        $previo = $movimiento->stock_resultante - $movimiento->cantidad;
        $verbo  = $movimiento->cantidad > 0 ? 'subió' : 'bajó';
        $signo  = $movimiento->cantidad > 0 ? '+' : '';

        // El mensaje dice exactamente qué pasó, con los dos números a la vista.
        return redirect()
            ->route('stock.index', ['producto_id' => $producto->id])
            ->with('exito', "El stock de «{$producto->nombre}» {$verbo} de {$previo} a "
                ."{$movimiento->stock_resultante} ({$signo}{$movimiento->cantidad}).");
    }

    
    /** Los filtros del kardex, en un solo lugar: lo que la pantalla muestra es lo que se imprime. */
    private function filtradas(MovimientoFiltroRequest $request): Builder
    {
        return MovimientoStock::query()
            ->deProducto($request->query('producto_id'))
            ->deTipo($request->query('tipo'))
            ->deUsuario($request->query('usuario_id'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}