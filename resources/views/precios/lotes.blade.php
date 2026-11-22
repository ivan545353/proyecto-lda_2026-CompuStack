@extends('layouts.app')

{{-- Shell (regla 37): los lotes y cuál se puede deshacer los trae pantallas/ajuste-masivo.js. --}}

@section('title', 'Historial de ajustes')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Historial de ajustes</h1>
            <p class="text-body-secondary small mb-0">
                Cada ajuste masivo quedó registrado con su criterio. El último se puede deshacer.
            </p>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2">
            @can('precio.ajustar_masivo')
                <a href="{{ route('precios.ajuste') }}" class="btn btn-acento text-nowrap">
                    <i class="bi bi-percent" aria-hidden="true"></i> Nuevo ajuste
                </a>
            @endcan

            <a href="{{ route('precios.index') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-sliders" aria-hidden="true"></i> Volver a Precios
            </a>
        </div>
    </div>

    <div data-pantalla="lotes">
        {{-- La acción de la pantalla, arriba y sola: deshacer el último. Nace oculta; la muestra el JS
             si el servidor dice que hay uno que se puede deshacer. --}}
        <section class="card border-0 shadow-sm mb-4" data-reversible hidden>
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge text-bg-dark">Último ajuste</span>
                            <span class="fw-semibold" data-campo="operacion_texto"></span>
                            <span class="badge bg-dark-subtle text-dark-emphasis border" data-campo="parametro_texto"></span>
                        </div>

                        <div class="text-body-secondary small" data-campo="resumen"></div>
                    </div>

                    @can('precio.deshacer_ajuste')
                        <button type="button" class="btn btn-outline-dark text-nowrap" data-deshacer>
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer este ajuste
                        </button>
                    @endcan
                </div>

                {{-- La explicación de por qué sólo el último va plegada: se lee una vez y después estorba. --}}
                <button
                    class="btn btn-link btn-sm text-decoration-none text-body-secondary p-0 mt-3 d-inline-flex align-items-center gap-1"
                    type="button" data-bs-toggle="collapse" data-bs-target="#porQueSoloElUltimo" aria-expanded="false"
                    aria-controls="porQueSoloElUltimo">
                    <i class="bi bi-question-circle" aria-hidden="true"></i>
                    <span>¿Por qué sólo se puede deshacer el último?</span>
                </button>

                <div class="collapse pt-2 mt-2 border-top small text-body-secondary" id="porQueSoloElUltimo">
                    Cada ajuste guarda el precio que cada producto tenía antes, y ese precio vale
                    mientras no haya pasado nada después. Deshacer el ajuste de la semana pasada
                    devolvería precios que dos ajustes posteriores ya movieron. Deshacer no pisa
                    nada: escribe un ajuste nuevo que devuelve los precios, igual que una venta
                    cobrada se devuelve en lugar de anularse. Y un producto cuyo precio se cambió
                    después del ajuste no se devuelve: se informa aparte.
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Todos los ajustes</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="El criterio quedó congelado como texto: dice sobre qué conjunto corrió el ajuste aunque la categoría o el proveedor se hayan renombrado después."
                    aria-label="Información sobre el historial de ajustes">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Ajustes masivos de precios, del más reciente al más antiguo</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Operación</th>
                            <th scope="col" class="d-none d-lg-table-cell">Sobre qué corrió</th>
                            <th scope="col" class="text-end">P.Alcanzados</th>
                            <th scope="col" class="d-none d-md-table-cell">Quién</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    {{-- Sin filtros no hay vacío por filtro: el único vacío posible es que todavía no se
                         haya hecho ningún ajuste. --}}
                    <tbody data-listado-filas data-columnas="6"
                        data-vacio="Todavía no se hizo ningún ajuste masivo."
                        data-vacio-filtrado="Todavía no se hizo ningún ajuste masivo.">
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Ningún estado se comunica sólo con color: el badge lleva texto. --}}
            <template data-listado-fila>
                <tr>
                    <td class="text-nowrap small" data-campo="fecha"></td>
                    <td>
                        <span class="fw-semibold" data-campo="operacion_texto"></span>
                        <span class="badge bg-dark-subtle text-dark-emphasis border ms-1" data-campo="parametro_texto"></span>
                    </td>
                    <td class="d-none d-lg-table-cell small text-body-secondary" data-campo="criterio_texto"></td>
                    <td class="text-end fw-semibold" data-campo="alcanzados"></td>
                    <td class="d-none d-md-table-cell small" data-campo="usuario"></td>
                    <td class="text-nowrap">
                        <span class="badge" data-campo="estado"></span>
                        <span class="badge text-bg-warning ms-1" data-campo="omitidos" hidden></span>
                    </td>
                </tr>
            </template>

            <div class="card-body pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2" data-listado-pie hidden>
                <p class="text-body-secondary small mb-0" data-listado-resumen></p>
                <div data-listado-paginas></div>
            </div>
        </section>
    </div>
@endsection
