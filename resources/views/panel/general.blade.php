@use('App\Models\OrdenCompra')

@extends('layouts.app')

@section('title', 'Panel general')

{{-- Shell (tramo H, regla 37): los números, sus comparaciones, las dos series y el período llegan por
     `GET /api/v1/panel/general`. Las tablas plegadas y el gráfico se llenan con las mismas filas. --}}
@section('content')
    <div data-pantalla="panel" data-ruta="/panel/general" aria-busy="true">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Panel general</h1>
                <p class="text-body-secondary small mb-0"><span data-periodo>Cargando el período…</span>.</p>
            </div>

            @can('panel.ver_propio')
                <a href="{{ route('panel') }}" class="btn btn-outline-dark text-nowrap">
                    <i class="bi bi-person" aria-hidden="true"></i> Ver mi actividad
                </a>
            @endcan
        </div>

        @include('panel._periodo', ['ruta' => 'panel.general'])

        <div class="row g-3 mb-4">
            @include('panel._tarjeta', [
                'ancho' => 'col-12 col-md-4',
                'titulo' => 'Cobrado en el período',
                'campo' => 'cobrado',
                'formato' => 'pesos',
                'comparar' => 'cobrado',
                'nota' => 'La plata que quedó: las devoluciones descuentan.',
            ])

            @include('panel._tarjeta', [
                'ancho' => 'col-12 col-md-4',
                'titulo' => 'Facturado en el período',
                'campo' => 'facturado',
                'formato' => 'pesos',
                'comparar' => 'facturado',
                'nota' => 'Lo que se vendió. No descuenta devoluciones.',
            ])

            @include('panel._tarjeta', [
                'ancho' => 'col-12 col-md-4',
                'titulo' => 'Margen bruto',
                'campo' => 'margen_bruto',
                'formato' => 'pesos',
                'comparar' => 'margen_bruto',
                'nota' => 'Antes de descuentos. Descuentos otorgados:',
                'notaCampo' => 'descuentos',
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
                'campo' => 'stock_critico',
                'enlace' => route('compras.sugeridos'),
                'permiso' => 'compra.crear',
                'rotulo' => 'Para reponer: __N__. Ver los sugeridos de reposición',
                'nota' => 'Por debajo del mínimo, sin contar lo que ya viene en camino.',
            ])

            @include('panel._tarjeta', [
                'ancho' => 'col-12 col-md-6',
                'titulo' => 'Órdenes de compra abiertas',
                'campo' => 'compras_abiertas',
                'enlace' => route('compras.index', ['estado' => OrdenCompra::ABIERTAS]),
                'permiso' => 'compra.ver',
                'rotulo' => 'Órdenes de compra abiertas: __N__. Ver el listado',
                'nota' => 'Esperando aprobación, envío o recepción.',
                'detalle' => true,
                'detalleEnlace' => route('compras.index', ['estado' => 'borrador', 'origen' => 'reposicion']),
            ])
        </div>

        <div class="alert alert-light border" role="status" data-si="vacio" hidden>
            No hubo ventas cobradas en este período. Probá con otras fechas.
        </div>

        {{-- Los números del gráfico salen agregados del servidor; acá sólo se les pone
             el rótulo que lee una persona. --}}
        <div class="row g-4 mb-4" data-si="con_ventas" hidden>
            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-1">Facturado por día</h2>
                        <p class="text-body-secondary small mb-3">Por fecha de emisión de la venta.</p>

                        <canvas data-grafico-dias role="img"
                            aria-label="Facturado de cada día del período. Los mismos números están en la tabla «Facturado por día», en «Ver los números», debajo del gráfico."></canvas>

                        {{-- `details` y no un collapse: la tabla se abre sin depender de Bootstrap. --}}
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
                                    <tbody data-filas="por_dia"></tbody>
                                </table>
                            </div>
                        </details>

                        <template data-plantilla="por_dia">
                            <tr>
                                <td class="text-nowrap" data-campo="dia"></td>
                                <td class="text-end" data-campo="cantidad"></td>
                                <td class="text-end" data-campo="facturado"></td>
                            </tr>
                        </template>
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
                                    <tbody data-filas="por_metodo"></tbody>
                                </table>
                            </div>
                        </details>

                        <template data-plantilla="por_metodo">
                            <tr>
                                <td data-campo="metodo_texto"></td>
                                <td class="text-end d-none d-md-table-cell" data-campo="movimientos"></td>
                                {{-- El signo va en el número y no sólo en el color. --}}
                                <td class="text-end" data-campo="neto"></td>
                            </tr>
                        </template>
                    </div>
                </div>
            </div>
        </div>

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
    </div>
@endsection
