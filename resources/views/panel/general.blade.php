@use('App\Support\Importe')
@use('Carbon\Carbon')
@use('App\Models\Pago')

@extends('layouts.app')

@section('title', 'Panel general')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Panel general</h1>
            <p class="text-body-secondary small mb-0">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}.
            </p>
        </div>

        @can('panel.ver_propio')
            <a href="{{ route('panel') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-person" aria-hidden="true"></i> Ver mi actividad
            </a>
        @endcan
    </div>

    @include('panel._periodo', ['ruta' => 'panel.general'])

    {{-- «Cobrado» y «facturado» responden preguntas distintas y dan números distintos:
         uno descuenta las devoluciones y el otro no. Van en recuadros separados y con
         su aclaración, nunca sumados en el mismo. --}}
    <div class="row g-3 mb-4">
        @include('panel._tarjeta', [
            'titulo' => 'Cobrado en el período',
            'valor' => Importe::pesos($metricas['cobrado']),
            'nota' => 'La plata que quedó: las devoluciones descuentan.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Facturado en el período',
            'valor' => Importe::pesos($metricas['facturado']),
            'nota' => 'Lo que se vendió. No descuenta devoluciones.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Ventas cobradas',
            'valor' => $metricas['cantidad'],
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Ticket promedio',
            'valor' => Importe::pesos($metricas['ticket']),
        ])
    </div>

    <div class="row g-3 mb-4">
        @include('panel._tarjeta', [
            'titulo' => 'Margen bruto',
            'valor' => Importe::pesos($metricas['margenBruto']),
            'nota' => 'Antes de descuentos. Sale del costo congelado en cada línea.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Descuentos otorgados',
            'valor' => Importe::pesos($metricas['descuentos']),
            'nota' => 'Lo que el margen bruto todavía no resta.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Stock crítico',
            'valor' => $metricas['stockCritico'],
            'nota' => 'Productos en o por debajo del mínimo, a hoy.',
        ])

        @include('panel._tarjeta', [
            'titulo' => 'Órdenes de compra abiertas',
            'valor' => $metricas['comprasAbiertas'],
            'nota' => 'Esperando aprobación, envío o recepción.',
        ])
    </div>

    @if ($metricas['cantidad'] === 0 && $metricas['porMetodo']->isEmpty())
        <div class="alert alert-light border" role="status">
            No hubo ventas cobradas en este período. Probá con otras fechas.
        </div>
    @else
        {{-- Los números del gráfico salen agregados del servidor; acá sólo se les pone
             el rótulo que lee una persona. --}}
        @php
            $serieDias = $metricas['porDia']
                ->map(
                    fn($fila) => [
                        'dia' => Carbon::parse($fila->dia)->format('d/m'),
                        'facturado' => (float) $fila->facturado,
                    ],
                )
                ->values();

            $serieMetodos = $metricas['porMetodo']
                ->map(
                    fn($fila) => [
                        'metodo' => Pago::METODOS[$fila->metodo] ?? $fila->metodo,
                        'neto' => (float) $fila->neto,
                    ],
                )
                ->values();
        @endphp

        <script type="application/json" id="facturadoPorDia">@json($serieDias)</script>
        <script type="application/json" id="cobradoPorMetodo">@json($serieMetodos)</script>

        <div class="row g-4 mb-4">
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">Facturado por día</h2>
                        <p class="text-body-secondary small mb-3">
                            El detalle, día por día, está en la tabla de abajo.
                        </p>

                        <canvas data-grafico-dias role="img"
                            aria-label="Facturado de cada día del período. Los mismos números están en la tabla «Facturado por día»."></canvas>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">Cobrado por medio de pago</h2>
                        <p class="text-body-secondary small mb-3">
                            Una barra hacia abajo es un medio por el que volvió más de lo que entró.
                        </p>

                        <canvas data-grafico-metodos role="img"
                            aria-label="Neto cobrado por cada medio de pago. Los mismos números están en la tabla «Cobrado por medio de pago»."></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-0">Ranking de vendedores</h2>
                        <p class="text-body-secondary small mb-0">Quién vendió, por lo facturado.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">Vendedores del período ordenados por lo facturado</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Vendedor</th>
                                    <th scope="col" class="text-end">Ventas</th>
                                    <th scope="col" class="text-end">Facturado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metricas['rankingVendedores'] as $fila)
                                    <tr>
                                        <td>{{ $fila->apellido }}, {{ $fila->nombre }}</td>
                                        <td class="text-end">{{ $fila->cantidad }}</td>
                                        <td class="text-end">{{ Importe::pesos($fila->facturado) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-body-secondary small py-3">
                                            Ninguna venta del período tiene vendedor asignado.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-0">Productos más vendidos</h2>
                        <p class="text-body-secondary small mb-0">Por unidades, descontando lo devuelto.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">Productos más vendidos del período</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Producto</th>
                                    <th scope="col" class="text-end">Unidades</th>
                                    <th scope="col" class="text-end d-none d-sm-table-cell">Importe</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metricas['masVendidos'] as $fila)
                                    <tr>
                                        <td>
                                            <span class="fw-semibold">{{ $fila->nombre }}</span>
                                            <div class="small text-body-secondary">{{ $fila->codigo }}</div>
                                        </td>
                                        <td class="text-end">{{ $fila->unidades }}</td>
                                        <td class="text-end d-none d-sm-table-cell">
                                            {{ Importe::pesos($fila->importe) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-body-secondary small py-3">
                                            Todo lo vendido en el período volvió.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-0">Cobrado por medio de pago</h2>
                        <p class="text-body-secondary small mb-0">
                            Un medio puede quedar negativo si volvió por ahí más de lo que entró.
                        </p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">Neto cobrado por medio de pago</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Medio</th>
                                    <th scope="col" class="text-end">Movimientos</th>
                                    <th scope="col" class="text-end">Neto</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metricas['porMetodo'] as $fila)
                                    <tr>
                                        <td>{{ Pago::METODOS[$fila->metodo] ?? $fila->metodo }}</td>
                                        <td class="text-end">{{ $fila->movimientos }}</td>
                                        {{-- El signo va en el número y no sólo en el color. --}}
                                        <td class="text-end {{ (float) $fila->neto < 0 ? 'text-danger-emphasis' : '' }}">
                                            {{ Importe::pesos($fila->neto) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-body-secondary small py-3">
                                            No se registró ningún movimiento de dinero en el período.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-0">Facturado por día</h2>
                        <p class="text-body-secondary small mb-0">Por fecha de emisión de la venta.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <caption class="visually-hidden">Facturado de cada día del período</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Día</th>
                                    <th scope="col" class="text-end">Ventas</th>
                                    <th scope="col" class="text-end">Facturado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metricas['porDia'] as $fila)
                                    <tr>
                                        <td class="text-nowrap">{{ Carbon::parse($fila->dia)->format('d/m/Y') }}</td>
                                        <td class="text-end">{{ $fila->cantidad }}</td>
                                        <td class="text-end">{{ Importe::pesos($fila->facturado) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-body-secondary small py-3">
                                            Sin ventas cobradas en el período.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
