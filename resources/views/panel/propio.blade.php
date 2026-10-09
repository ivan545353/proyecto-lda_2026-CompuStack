@use('App\Support\Importe')

@extends('layouts.app')

@section('title', 'Mi panel')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Mi panel</h1>
            <p class="text-body-secondary small mb-0">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}.
            </p>
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
            'valor' => $metricas['vendi']['cantidad'],
            'nota' => 'Emitidas por mí y ya cobradas.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Facturado',
            'valor' => Importe::pesos($metricas['vendi']['facturado']),
            'nota' => 'No descuenta devoluciones.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Ticket promedio',
            'valor' => Importe::pesos($metricas['vendi']['ticket']),
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Presupuestos abiertos',
            'valor' => $metricas['presupuestosAbiertos'],
            'nota' => 'A hoy, sin importar el período.',
        ])
    </div>

    <h2 class="h5 mb-3">Lo que cobré</h2>

    <div class="row g-3 mb-4">
        @include('panel._tarjeta', [
            'titulo' => 'Cobrado',
            'valor' => Importe::pesos($metricas['cobre']['neto']),
            'nota' => 'Neto: las devoluciones que registré descuentan.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Movimientos',
            'valor' => $metricas['cobre']['movimientos'],
            'nota' => 'Cobros y devoluciones, cada uno con su signo.',
        ])
    </div>

    @if ($metricas['vendi']['cantidad'] === 0 && $metricas['cobre']['movimientos'] === 0)
        <div class="alert alert-light border mb-0" role="status">
            No registraste ventas ni cobros en este período. Probá con otras fechas.
        </div>
    @endif
@endsection
