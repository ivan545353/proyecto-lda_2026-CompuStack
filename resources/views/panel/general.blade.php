@use('App\Support\Importe')
@use('Carbon\Carbon')
@use('App\Models\Pago')
@use('App\Models\OrdenCompra')
@use('App\Support\Comparacion')

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

    @php
        $antes = $metricas['anterior'];
        $contra = 'entre el ' . $antes['desde']->format('d/m') . ' y el ' . $antes['hasta']->format('d/m');
        $borradores = $metricas['borradoresDeReposicion'];
    @endphp

    <div class="row g-3 mb-4">
        @include('panel._tarjeta', [
            'ancho' => 'col-12 col-md-4',
            'titulo' => 'Cobrado en el período',
            'valor' => Importe::pesos($metricas['cobrado']),
            'comparacion' => Comparacion::entre($metricas['cobrado'], $antes['cobrado']),
            'periodo' => $contra,
            'anterior' => Importe::pesos($antes['cobrado']),
            'nota' => 'La plata que quedó: las devoluciones descuentan.',
        ])

        @include('panel._tarjeta', [
            'ancho' => 'col-12 col-md-4',
            'titulo' => 'Facturado en el período',
            'valor' => Importe::pesos($metricas['facturado']),
            'comparacion' => Comparacion::entre($metricas['facturado'], $antes['facturado']),
            'periodo' => $contra,
            'anterior' => Importe::pesos($antes['facturado']),
            'nota' => 'Lo que se vendió. No descuenta devoluciones.',
        ])

        @include('panel._tarjeta', [
            'ancho' => 'col-12 col-md-4',
            'titulo' => 'Margen bruto',
            'valor' => Importe::pesos($metricas['margenBruto']),
            'comparacion' => Comparacion::entre($metricas['margenBruto'], $antes['margenBruto']),
            'periodo' => $contra,
            'anterior' => Importe::pesos($antes['margenBruto']),
            'nota' =>
                'Antes de descuentos. Descuentos otorgados: ' . Importe::pesos($metricas['descuentos']) . '.',
        ])
    </div>

    <h2 class="h5 mb-1">Requiere atención</h2>
    <p class="text-body-secondary small mb-3">
        A hoy, sin importar el período. No se comparan con antes: el sistema no guarda cuántas había.
    </p>

    <div class="row g-3 mb-4">
        @include('panel._tarjeta', [
            'ancho' => 'col-12 col-md-6',
            'titulo' => 'Para reponer',
            'valor' => $metricas['stockCritico'],
            'enlace' => route('compras.sugeridos'),
            'permiso' => 'compra.crear',
            'rotulo' => "Para reponer: {$metricas['stockCritico']}. Ver los sugeridos de reposición",
            'nota' => 'Por debajo del mínimo, sin contar lo que ya viene en camino.',
        ])

        @include('panel._tarjeta', [
            'ancho' => 'col-12 col-md-6',
            'titulo' => 'Órdenes de compra abiertas',
            'valor' => $metricas['comprasAbiertas'],
            'enlace' => route('compras.index', ['estado' => OrdenCompra::ABIERTAS]),
            'permiso' => 'compra.ver',
            'rotulo' => "Órdenes de compra abiertas: {$metricas['comprasAbiertas']}. Ver el listado",
            'nota' => 'Esperando aprobación, envío o recepción.',
            'detalle' => match (true) {
                $borradores === 1 => '1 borrador lo generó la reposición y espera aprobación.',
                $borradores > 1 => "{$borradores} borradores los generó la reposición y esperan aprobación.",
                default => null,
            },
            'detalleEnlace' => route('compras.index', ['estado' => 'borrador', 'origen' => 'reposicion']),
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
        @endphp

        <script type="application/json" id="facturadoPorDia">@json($serieDias)</script>

        <div class="row g-4 mb-4">
            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">Facturado por día</h2>
                        <p class="text-body-secondary small mb-3">Por fecha de emisión de la venta.</p>

                        <canvas data-grafico-dias role="img"
                            aria-label="Facturado de cada día del período. Los mismos números están en la tabla «Facturado por día», en «Ver los números», debajo del gráfico."></canvas>

                        {{-- `details` y no un collapse: sin JavaScript la tabla se tiene que poder abrir. --}}
                        <details class="mt-3" data-numeros>
                            <summary class="small fw-semibold">Ver los números</summary>

                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle mb-0">
                                    <caption class="visually-hidden">Facturado por día: cada día del período</caption>
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
                                                <td class="text-nowrap">{{ Carbon::parse($fila->dia)->format('d/m/Y') }}
                                                </td>
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
                        </details>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">Cobrado por medio de pago</h2>
                        <p class="text-body-secondary small mb-3">
                            Un medio puede quedar negativo si volvió por ahí más de lo que entró.
                        </p>

                        <details data-numeros>
                            <summary class="small fw-semibold">Ver los números</summary>

                            <div class="table-responsive mt-2">
                                <table class="table table-sm align-middle mb-0">
                                    <caption class="visually-hidden">Neto cobrado por medio de pago</caption>
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Medio</th>
                                            <th scope="col" class="text-end d-none d-md-table-cell">Movimientos</th>
                                            <th scope="col" class="text-end">Neto</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($metricas['porMetodo'] as $fila)
                                            <tr>
                                                <td>{{ Pago::METODOS[$fila->metodo] ?? $fila->metodo }}</td>
                                                <td class="text-end d-none d-md-table-cell">{{ $fila->movimientos }}</td>
                                                {{-- El signo va en el número y no sólo en el color. --}}
                                                <td
                                                    class="text-end {{ (float) $fila->neto < 0 ? 'text-danger-emphasis' : '' }}">
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
                        </details>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- El ranking y los más vendidos se miran una vez por mes: son informes con su
         período y su paginación, no avisos del día. El enlace lleva el período puesto. --}}
    @can('panel.ver_global')
        <div class="card border-0 shadow-sm" data-informes>
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h2 class="h6 mb-1">Informes del período</h2>
                    <p class="text-body-secondary small mb-0">Quién vendió y qué se vendió más.</p>
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2">
                    <a href="{{ route('ventas.vendedores', request()->only(['desde', 'hasta'])) }}"
                        class="btn btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1">
                        <i class="bi bi-trophy" aria-hidden="true"></i> Ranking de vendedores
                    </a>
                    <a href="{{ route('ventas.productos', request()->only(['desde', 'hasta'])) }}"
                        class="btn btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1">
                        <i class="bi bi-box-seam" aria-hidden="true"></i> Productos más vendidos
                    </a>
                </div>
            </div>
        </div>
    @endcan
@endsection
