@extends('layouts.app')

@section('title', 'Productos más vendidos')

{{-- Shell (tramo H, regla 37): las filas y el período llegan por `GET /api/v1/informes/productos`. --}}
@section('content')
    <div data-pantalla="informe" data-ruta="/informes/productos">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1">Productos más vendidos</h1>
                <p class="text-body-secondary small mb-0">
                    <span data-periodo>Cargando el período…</span>. Por unidades, descontando lo devuelto.
                </p>
            </div>

            <a href="{{ route('panel.general', request()->only(['desde', 'hasta'])) }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al panel
            </a>
        </div>

        @include('panel._periodo', ['ruta' => 'ventas.productos'])

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Productos más vendidos del período, por unidades</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="text-end d-none d-md-table-cell">Puesto</th>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Unidades</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Importe</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="4" data-ver-todos="Ver el mes en curso"
                        data-vacio="Todavía no hay productos vendidos este mes."
                        data-vacio-filtrado="Ningún producto vendido entre esas fechas, o volvió todo. Probá con otro período.">
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template data-listado-fila>
                <tr>
                    <td class="text-end d-none d-md-table-cell" data-campo="puesto"></td>
                    <td>
                        <span class="fw-semibold" data-campo="nombre"></span>
                        <div class="small text-body-secondary" data-campo="codigo"></div>
                    </td>
                    <td class="text-end" data-campo="unidades"></td>
                    <td class="text-end d-none d-md-table-cell" data-campo="importe"></td>
                </tr>
            </template>

            <div class="card-body d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2" data-listado-pie hidden>
                <p class="text-body-secondary small mb-0 text-center text-sm-start" data-listado-resumen></p>
                <div class="d-flex justify-content-center" data-listado-paginas></div>
            </div>
        </div>
    </div>
@endsection
