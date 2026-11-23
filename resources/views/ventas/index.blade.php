@extends('layouts.app')

@section('title', 'Ventas')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Ventas</h1>
            <p class="text-body-secondary small mb-0">
                Un presupuesto no descuenta stock ni genera comprobante: se convierte en venta
                cuando se cobra, y ahí sale la mercadería del depósito.
            </p>
        </div>

        @can('venta.crear')
            <a href="{{ route('ventas.create') }}"
                class="btn btn-acento text-nowrap align-self-stretch align-self-sm-auto d-inline-flex align-items-center justify-content-center gap-1">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva venta
            </a>
        @endcan
    </div>

    {{-- Shell (tramo H, regla 37): la tabla, el paginado y los vendedores del filtro llegan por la API.
         Los chips arman su texto con la URL y con constantes; el nombre del cliente y del vendedor lo
         pone el JS con lo que el servidor devuelve en `criterios`. --}}
    @php
        $hayFiltros = request()->hasAny(['q', 'estado', 'cliente_id', 'vendedor_id', 'canal', 'pago', 'desde', 'hasta']);

        $chips = collect([
            'q' => request('q') ? 'Texto: "' . Str::limit(request('q'), 18) . '"' : null,
            'estado' => request('estado')
                ? 'Estado: ' . (App\Models\Venta::ESTADOS[request('estado')] ?? request('estado'))
                : null,
            'canal' => request('canal')
                ? 'Canal: ' . (App\Models\Venta::CANALES[request('canal')] ?? request('canal'))
                : null,
            'pago' => isset(App\Models\Venta::FILTROS_PAGO[request('pago')])
                ? 'Pago: ' . App\Models\Venta::FILTROS_PAGO[request('pago')]
                : null,
            'desde' => request('desde') ? 'Desde: ' . request('desde') : null,
            'hasta' => request('hasta') ? 'Hasta: ' . request('hasta') : null,
        ])->filter();

        $chipsConNombre = ['cliente_id' => 'Cliente', 'vendedor_id' => 'Vendedor'];
    @endphp

    <div data-pantalla="ventas">
    <form method="GET" action="{{ route('ventas.index') }}" class="card card-body border-0 shadow-sm mb-4" data-filtros-ventas>
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="q" class="form-label">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-body-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="V-00042 o nombre del cliente">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach (App\Models\Venta::ESTADOS_EN_USO as $valor)
                        <option value="{{ $valor }}" @selected(request('estado') === $valor)>
                            {{ App\Models\Venta::ESTADOS[$valor] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="vendedor_id" class="form-label">Vendedor</label>
                <select class="form-select" id="vendedor_id" name="vendedor_id" data-buscable data-buscable-diferido
                    data-seleccionada="{{ request('vendedor_id') }}">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="col-12 col-sm-4">
                <label for="canal" class="form-label">Canal</label>
                <select class="form-select" id="canal" name="canal">
                    <option value="">Todos</option>
                    @foreach (App\Models\Venta::CANALES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('canal') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-4">
                <label for="pago" class="form-label">Pago</label>
                <select class="form-select" id="pago" name="pago">
                    <option value="">Cualquiera</option>
                    @foreach (App\Models\Venta::FILTROS_PAGO as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('pago') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-4">
                <label for="desde" class="form-label">Emitida desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="{{ request('desde') }}">
            </div>

            <div class="col-12 col-sm-4">
                <label for="hasta" class="form-label">Emitida hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="{{ request('hasta') }}">
            </div>

            {{-- El filtro por cliente llega por enlace, desde la ficha del cliente.
                 No tiene campo acá, pero sí su chip, para que se pueda quitar. --}}
            @if (request()->filled('cliente_id'))
                <input type="hidden" name="cliente_id" value="{{ request('cliente_id') }}">
            @endif

            <div
                class="col-12 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('ventas.index') }}"
                                class="badge rounded-pill text-bg-dark text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                title="Hacé clic para limpiar todos los filtros">
                                <i class="bi bi-funnel-fill text-white-50" aria-hidden="true"></i>
                                <span>Filtros aplicados</span>
                                <i class="bi bi-x-circle-fill text-white-50 ms-1" aria-hidden="true"></i>
                            </a>

                            @foreach ($chips as $parametro => $texto)
                                <a href="{{ request()->fullUrlWithoutQuery([$parametro, 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar este filtro">
                                    {{ $texto }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endforeach

                            @foreach ($chipsConNombre as $parametro => $rotulo)
                                @if (request()->filled($parametro))
                                    <a href="{{ request()->fullUrlWithoutQuery([$parametro, 'pagina']) }}"
                                        class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                        title="Quitar este filtro">
                                        {{ $rotulo }}: <span data-nombre-filtro="{{ $parametro }}">el elegido</span>
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <span class="text-body-secondary">Mostrando todas las ventas</span>
                    @endif
                </div>

                <div class="d-flex gap-2 align-self-stretch align-self-sm-auto justify-content-sm-end">
                    @if ($hayFiltros)
                        <a href="{{ route('ventas.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <x-imprimir api="/ventas/impresion" :listado="new \App\Impresion\ListadoVentas()" class="flex-fill flex-sm-grow-0" />

                    <button type="submit"
                        class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="min-width: 40rem;">
                <caption class="visually-hidden">Listado de ventas y presupuestos</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 7.5rem;">Venta</th>
                        <th scope="col" style="min-width: 12rem;">Cliente</th>
                        <th scope="col" class="d-none d-md-table-cell" style="min-width: 10rem;">Vendedor</th>
                        <th scope="col" class="d-none d-md-table-cell" style="min-width: 6.5rem;">Canal</th>
                        <th scope="col" style="min-width: 7rem;">Estado</th>
                        <th scope="col" class="text-center d-none d-lg-table-cell" style="min-width: 5.5rem;">
                            Productos
                        </th>
                        <th scope="col" class="text-end" style="min-width: 8rem;">Total</th>
                        <th scope="col" class="text-end" style="min-width: 5rem;">Acciones</th>
                    </tr>
                </thead>
                <tbody data-listado-filas data-columnas="8" data-ver-todos="Ver todas"
                    data-vacio="Todavía no hay ventas registradas."
                    data-vacio-filtrado="Ninguna venta coincide con el filtro.">
                    <tr>
                        <td colspan="8" class="text-center text-body-secondary py-4">Cargando…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template data-listado-fila>
            <tr>
                <td class="text-nowrap">
                    <a href="{{ route('ventas.show', '__ID__') }}" class="fw-semibold link-dark" data-campo="numero"></a>
                    <div class="small text-body-secondary" data-campo="fecha"></div>
                </td>
                <td data-campo="cliente_texto"></td>
                <td class="d-none d-md-table-cell" data-campo="vendedor"></td>
                <td class="d-none d-md-table-cell" data-campo="canal_texto" data-canal></td>
                <td>
                    <span class="badge" data-campo="estado_texto"></span>
                    <span class="badge text-bg-warning d-inline-block mt-1" data-sin-stock hidden>
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        Sin stock suficiente
                    </span>
                </td>
                <td class="text-center d-none d-lg-table-cell" data-campo="productos"></td>
                <td class="text-end text-nowrap" data-campo="total"></td>
                <td class="text-end text-nowrap">
                    <a href="{{ route('ventas.show', '__ID__') }}" class="btn btn-sm btn-outline-dark" data-ver>
                        <i class="bi bi-eye" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Ver</span>
                    </a>

                    {{-- Sólo un presupuesto se edita: lo dice `editable`, y la API lo vuelve a exigir. --}}
                    @can('venta.editar')
                        <a href="{{ route('ventas.edit', '__ID__') }}" hidden data-editar
                            class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Editar</span>
                        </a>
                    @endcan
                </td>
            </tr>
        </template>

        <div class="card-body d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2" data-listado-pie hidden>
            <p class="text-body-secondary small mb-0 text-center text-sm-start" data-listado-resumen></p>
            <div class="d-flex justify-content-center" data-listado-paginas></div>
        </div>
    </div>
    </div>
@endsection
