@extends('layouts.app')

@section('title', 'Mi panel')

{{-- Shell (tramo H, regla 37): los números, sus comparaciones y el período llegan por `GET /api/v1/panel`. --}}
@section('content')
    <div data-pantalla="panel" data-ruta="/panel" aria-busy="true">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Mi panel</h1>
                <p class="text-body-secondary small mb-0"><span data-periodo>Cargando el período…</span>.</p>
            </div>

            @can('panel.ver_global')
                <a href="{{ route('panel.general') }}" class="btn btn-outline-dark text-nowrap">
                    <i class="bi bi-bar-chart" aria-hidden="true"></i> Ver el conjunto
                </a>
            @endcan
        </div>

        @include('panel._periodo', ['ruta' => 'panel'])

        {{-- Vender y cobrar son dos cosas distintas y las hace gente distinta: el vendedor
             emite y el cajero cobra. Por eso son dos bloques y no uno. --}}
        <h2 class="h5 mb-3">Lo que vendí</h2>

        <div class="row g-3 mb-4">
            @include('panel._tarjeta', [
                'titulo' => 'Ventas cobradas',
                'campo' => 'vendi.cantidad',
                'comparar' => 'vendi_cantidad',
                'nota' => 'Emitidas por mí y ya cobradas.',
            ])

            @include('panel._tarjeta', [
                'titulo' => 'Facturado',
                'campo' => 'vendi.facturado',
                'formato' => 'pesos',
                'comparar' => 'vendi_facturado',
                'nota' => 'No descuenta devoluciones.',
            ])

            @include('panel._tarjeta', [
                'titulo' => 'Ticket promedio',
                'campo' => 'vendi.ticket',
                'formato' => 'pesos',
                'comparar' => 'vendi_ticket',
            ])

            @include('panel._tarjeta', [
                'titulo' => 'Presupuestos abiertos',
                'campo' => 'presupuestos_abiertos',
                'enlace' => route('ventas.index', ['estado' => 'presupuesto', 'vendedor_id' => auth()->id()]),
                'permiso' => 'venta.ver',
                'rotulo' => 'Presupuestos abiertos: __N__. Ver mis presupuestos',
                'nota' => 'A hoy, sin importar el período: no se compara con antes.',
            ])
        </div>

        <h2 class="h5 mb-3">Lo que cobré</h2>

        <div class="row g-3 mb-4">
            @include('panel._tarjeta', [
                'titulo' => 'Cobrado',
                'campo' => 'cobre.neto',
                'formato' => 'pesos',
                'comparar' => 'cobre_neto',
                'nota' => 'Neto: las devoluciones que registré descuentan.',
            ])

            @include('panel._tarjeta', [
                'titulo' => 'Movimientos',
                'campo' => 'cobre.movimientos',
                'comparar' => 'cobre_movimientos',
                'nota' => 'Cobros y devoluciones, cada uno con su signo.',
            ])
        </div>

        <div class="alert alert-light border mb-0" role="status" data-si="vacio" hidden>
            No registraste ventas ni cobros en este período. Probá con otras fechas.
        </div>
    </div>
@endsection
