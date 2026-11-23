@extends('layouts.app')

@section('title', 'Ranking de vendedores')

{{-- Shell (tramo H, regla 37): las filas, el total y el período llegan por `GET /api/v1/informes/vendedores`. --}}
@section('content')
    <div data-pantalla="informe" data-ruta="/informes/vendedores">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Ranking de vendedores</h1>
                <p class="text-body-secondary small mb-0">
                    <span data-periodo>Cargando el período…</span>. Quién vendió, por lo facturado.
                </p>
            </div>

            <a href="{{ route('panel.general', request()->only(['desde', 'hasta'])) }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al panel
            </a>
        </div>

        @include('panel._periodo', ['ruta' => 'ventas.vendedores'])

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Vendedores del período ordenados por lo facturado</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-end d-none d-md-table-cell">Puesto</th>
                            <th scope="col">Vendedor</th>
                            <th scope="col" class="text-end">Ventas</th>
                            <th scope="col" class="text-end">Facturado</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Ticket promedio</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="5" data-ver-todos="Ver el mes en curso"
                        data-vacio="Todavía no hay ventas cobradas este mes."
                        data-vacio-filtrado="Ninguna venta cobrada entre esas fechas. Probá con otro período.">
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>

                    {{-- La misma consulta sin agrupar: suma lo que listan todas las páginas. --}}
                    <tfoot class="table-light" data-total hidden>
                        <tr>
                            <th scope="row" colspan="2" class="d-none d-md-table-cell">Todos los vendedores</th>
                            <th scope="row" class="d-md-none">Todos</th>
                            <td class="text-end fw-semibold" data-campo="cantidad"></td>
                            <td class="text-end fw-semibold" data-campo="facturado"></td>
                            <td class="text-end fw-semibold d-none d-md-table-cell" data-campo="ticket"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <template data-listado-fila>
                <tr>
                    <td class="text-end d-none d-md-table-cell" data-campo="puesto"></td>
                    <td data-campo="vendedor"></td>
                    <td class="text-end" data-campo="cantidad"></td>
                    <td class="text-end" data-campo="facturado"></td>
                    <td class="text-end d-none d-md-table-cell" data-campo="ticket"></td>
                </tr>
            </template>

            <div class="card-body d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2" data-listado-pie hidden>
                <p class="text-body-secondary small mb-0 text-center text-sm-start" data-listado-resumen></p>
                <div class="d-flex justify-content-center" data-listado-paginas></div>
            </div>
        </div>
    </div>
@endsection
