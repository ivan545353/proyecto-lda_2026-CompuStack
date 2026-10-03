<?php

namespace App\Http\Controllers;

use App\Http\Requests\AjusteStockRequest;
use App\Http\Requests\MovimientoFiltroRequest;
use App\Models\MovimientoStock;
use App\Models\Producto;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Kardex y ajuste de inventario. Módulo nuevo: el sistema original no tenía
 * ningún registro de movimientos de stock (A-13).
 *
 * El controlador traduce entre HTTP y el servicio. No toca `productos.stock`:
 * eso lo hace sólo StockService, y siempre dejando un movimiento.
 *
 * Métodos:
 *   index()         el kardex, filtrado y paginado
 *   crearAjuste()   formulario de ajuste de inventario de un producto
 *   guardarAjuste() registra el ajuste
 */
class StockController extends Controller
{
    public function __construct(private StockService $service)
    {
    }

    public function index(MovimientoFiltroRequest $request): View
    {
        $movimientos = MovimientoStock::query()
            // Sólo las columnas que la vista usa. Para `usuario` eso significa
            // además que el hash de contraseña ni se trae de la base, que es la
            // corrección literal del `SELECT u.*` de C-1.
            ->with(['producto:id,codigo,nombre', 'usuario:id,nombre,apellido'])
            ->deProducto($request->query('producto_id'))
            ->deTipo($request->query('tipo'))
            ->deUsuario($request->query('usuario_id'))
            ->desde($request->query('desde'))
            ->hasta($request->query('hasta'))
            ->orderByDesc('created_at')
            // Desempate por id: una venta de tres líneas genera tres movimientos
            // en el mismo segundo, y sin un orden total el paginador puede
            // repetir o saltear filas entre páginas.
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock.index', [
            'movimientos' => $movimientos,

            // El producto del filtro, para mostrar su stock y ofrecer el ajuste
            // en contexto. El Form Request ya verificó que exista.
            'producto' => $request->filled('producto_id')
                ? Producto::find($request->integer('producto_id'))
                : null,

            'productos' => Producto::orderBy('nombre')->get(['id', 'codigo', 'nombre']),

            // Sólo quienes registraron algún movimiento: ofrecer en el filtro a
            // alguien que nunca movió stock es ofrecer un camino que devuelve
            // vacío.
            'usuarios' => User::query()
                ->whereIn('id', MovimientoStock::query()->whereNotNull('usuario_id')->select('usuario_id'))
                ->orderBy('apellido')->orderBy('nombre')
                ->get(['id', 'nombre', 'apellido']),
        ]);
    }

    public function crearAjuste(Producto $producto): View
    {
        return view('stock.ajuste', compact('producto'));
    }

    public function guardarAjuste(AjusteStockRequest $request, Producto $producto): RedirectResponse
    {
        $datos = $request->validated();

        // Si el stock cambió desde que se abrió el formulario, el servicio lanza
        // ReglaDeNegocioException y bootstrap/app.php devuelve al usuario al
        // formulario con el aviso y con lo que había escrito. Sin try/catch (M-30).
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
}