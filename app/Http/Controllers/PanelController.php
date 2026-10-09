<?php

namespace App\Http\Controllers;

use App\Http\Requests\PanelFiltroRequest;
use App\Services\PanelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// Dos pantallas y dos permisos, uno por acción (C-2): entrar al panel propio es una
// cosa y ver el conjunto es otra. El Administrativo tiene sólo `panel.ver_global` y no
// vende, así que una sola ruta con `can:panel.ver_propio` lo dejaría afuera.
class PanelController extends Controller
{
    // A dónde va alguien al entrar, en orden de preferencia.
    private const DESTINOS = [
        'panel.ver_global' => 'panel.general',
        'panel.ver_propio' => 'panel',
        'venta.ver'        => 'ventas.index',
        'producto.ver'     => 'productos.index',
    ];

    public function __construct(private PanelService $service)
    {
    }

    // La raíz y el login no pueden apuntar derecho al panel ahora que exige permiso: un
    // rol creado sin ningún permiso de panel recibiría un 403 sin navbar del que salir.
    // Es la forma de M-21 —un permiso que si no se asigna deja sin salida— atajada acá.
    public function inicio(Request $request): RedirectResponse
    {
        foreach (self::DESTINOS as $permiso => $ruta) {
            if ($request->user()->can($permiso)) {
                return redirect()->route($ruta);
            }
        }

        abort(403, 'Tu rol todavía no tiene ninguna pantalla asignada. Pedile a un administrador que revise sus permisos.');
    }

    public function propio(PanelFiltroRequest $request): View
    {
        return view('panel.propio', [
            'metricas' => $this->service->paraVendedor($request->user(), $request->desde(), $request->hasta()),
            'desde'    => $request->desde(),
            'hasta'    => $request->hasta(),
        ]);
    }

    public function general(PanelFiltroRequest $request): View
    {
        return view('panel.general', [
            'metricas' => $this->service->paraAdministrativo($request->desde(), $request->hasta()),
            'desde'    => $request->desde(),
            'hasta'    => $request->hasta(),
        ]);
    }
}