<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Venta;
use App\Services\VentaService;
use App\Support\MaquinaEstadosVenta;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Las pantallas de ventas y presupuestos. Desde el tramo H son shells (regla 37): los datos y cada
 * operación —emitir, editar, recotizar, cobrar, entregar, devolver, cancelar, imprimir— van por
 * `/api/v1/ventas` (`Api\V1\VentaController`), y esto sólo entrega la página.
 *
 * Métodos:
 *   index()        el listado
 *   show()         la ficha
 *   create()       el alta del presupuesto
 *   edit()         la edición; sólo de un presupuesto
 *   entrega()      la entrega parcial; sólo si el estado la admite
 *   devolucion()   la devolución; sólo si el estado la admite
 *
 * Las guardias de estado de las tres últimas no son la regla: la API la vuelve a exigir al mandar. Son la
 * red para una URL guardada en favoritos, que lleva a la ficha con el motivo.
 */
class VentaController extends Controller
{
    public function __construct(private VentaService $service)
    {
    }

    /** El shell del listado (tramo H, regla 37): las ventas y los vendedores llegan por `GET /api/v1/ventas`. */
    public function index(): View
    {
        return view('ventas.index');
    }

    /** El shell de la ficha (tramo H): la venta llega por `GET /api/v1/ventas/{venta}`. */
    public function show(Venta $venta): View
    {
        return view('ventas.show', ['venta' => $venta]);
    }

    /** El shell del alta (tramo H): emitir es `POST /api/v1/ventas`. El tope es del usuario, no de negocio. */
    public function create(): View
    {
        return view('ventas.form', $this->armador(new Venta));
    }

    /** El shell de la edición (tramo H): la venta llega por `GET /api/v1/ventas/{venta}`; guardar es `PATCH`. */
    public function edit(Venta $venta): View|RedirectResponse
    {
        if (! $venta->esPresupuesto()) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y ya no se puede "
                .'modificar: lo que se cobró no se corrige editando, se devuelve.',
            );
        }

        return view('ventas.form', $this->armador($venta));
    }

    /** El shell de la entrega parcial (tramo H): entregar es `POST /api/v1/ventas/{venta}/entregas`. */
    public function entrega(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puede($venta->estado, 'entregada_parcial')) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no se "
                .'entrega en partes. Se entrega lo que se cobró y todavía no salió del local.',
            );
        }

        return view('ventas.entrega', ['venta' => $venta]);
    }

    /** El shell de la devolución (tramo H): devolver es `POST /api/v1/ventas/{venta}/devoluciones`. */
    public function devolucion(Venta $venta): View|RedirectResponse
    {
        if (! MaquinaEstadosVenta::puedeDevolver($venta->estado)) {
            return redirect()->route('ventas.show', $venta)->with(
                'error',
                "La venta {$venta->numeroFormateado()} está «{$venta->estadoTexto()}» y en ese estado no "
                .'se devuelve. Se devuelve lo que se cobró: un presupuesto se cancela.',
            );
        }

        return view('ventas.devolucion', ['venta' => $venta]);
    }

    /** Lo que el formulario necesita y no es de negocio: el tope del usuario y si hay algo que vender. */
    private function armador(Venta $venta): array
    {
        return [
            'venta'        => $venta,
            'hayProductos' => Producto::where('activo', true)->exists(),
            'tope'         => $this->service->topeDeDescuento(request()->user()),
        ];
    }
}
