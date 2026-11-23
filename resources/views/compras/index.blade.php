@extends('layouts.app')

@section('title', 'Órdenes de compra')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Órdenes de compra</h1>
            <p class="text-body-secondary small mb-0">
                Toda orden nace en borrador y no sale sola: alguien la aprueba y recién entonces
                se le hace llegar al proveedor.
            </p>
        </div>

        @can('compra.crear')
            <a href="{{ route('compras.pedido') }}"
                class="btn btn-acento text-nowrap align-self-stretch align-self-sm-auto d-inline-flex align-items-center justify-content-center gap-1">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Armar pedido
            </a>
        @endcan
    </div>

    {{-- Shell (tramo G, regla 37): la tabla, el paginado y las opciones del proveedor llegan por la API.
         Los chips arman su texto con la URL y con constantes; el nombre del proveedor lo pone el JS. --}}
    @php
        $hayFiltros = request()->hasAny(['q', 'estado', 'origen', 'proveedor_id', 'desde', 'hasta']);

        $chips = collect([
            'q' => request('q') ? 'Texto: "' . Str::limit(request('q'), 18) . '"' : null,
            'estado' => request('estado')
                ? 'Estado: ' . (App\Models\OrdenCompra::ESTADOS_DEL_FILTRO[request('estado')] ?? request('estado'))
                : null,
            'origen' => request('origen')
                ? 'Origen: ' . (App\Models\OrdenCompra::ORIGENES[request('origen')] ?? request('origen'))
                : null,
            'desde' => request('desde') ? 'Desde: ' . request('desde') : null,
            'hasta' => request('hasta') ? 'Hasta: ' . request('hasta') : null,
        ])->filter();
    @endphp

    <div data-pantalla="compras">
    <form method="GET" action="{{ route('compras.index') }}" class="card card-body border-0 shadow-sm mb-4" data-filtros-compras>
        <p class="alert alert-danger" data-errores-formulario hidden></p>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="q" class="form-label">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-body-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="OC-00042 o nombre del proveedor"
                        aria-describedby="ayudaBuscarCompra">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="proveedor_id" class="form-label">Proveedor</label>
                <select class="form-select" id="proveedor_id" name="proveedor_id" data-buscable data-buscable-diferido
                    data-seleccionada="{{ request('proveedor_id') }}">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach (App\Models\OrdenCompra::ESTADOS_DEL_FILTRO as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('estado') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="origen" class="form-label">Origen</label>
                <select class="form-select" id="origen" name="origen">
                    <option value="">Todos</option>
                    @foreach (App\Models\OrdenCompra::ORIGENES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('origen') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="desde" class="form-label">Creada desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="{{ request('desde') }}">
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="hasta" class="form-label">Creada hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="{{ request('hasta') }}"
                    aria-describedby="ayudaRangoCompra">
            </div>

            <div
                class="col-12 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('compras.index') }}"
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

                            @if (request()->filled('proveedor_id'))
                                <a href="{{ request()->fullUrlWithoutQuery(['proveedor_id', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar este filtro">
                                    Proveedor: <span data-nombre-filtro="proveedor_id">el elegido</span>
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-body-secondary">Mostrando todas las órdenes</span>
                    @endif
                </div>

                <div class="d-flex gap-2 align-self-stretch align-self-sm-auto justify-content-sm-end">
                    @if ($hayFiltros)
                        <a href="{{ route('compras.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <x-imprimir api="/compras/impresion" :listado="new \App\Impresion\ListadoCompras()" class="flex-fill flex-sm-grow-0" />

                    <button type="submit"
                        class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <table class="table table-hover align-middle mb-0" style="min-width: 42rem;">
            <caption class="visually-hidden">Listado de órdenes de compra</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col" style="min-width: 7.5rem;">Orden</th>
                    <th scope="col" style="min-width: 12rem;">Proveedor</th>
                    <th scope="col" style="min-width: 7rem;">Estado</th>
                    <th scope="col" class="text-center d-none d-md-table-cell" style="min-width: 5.5rem;">Productos
                    </th>
                    <th scope="col" class="text-end" style="min-width: 8rem;">Total estimado</th>
                    <th scope="col" class="text-end" style="min-width: 5.5rem;">Acciones</th>
                </tr>
            </thead>
            <tbody data-listado-filas data-columnas="6" data-ver-todos="Ver todas"
                data-vacio="Todavía no hay órdenes de compra. Se cargan a mano, o las genera la tarea de reposición cuando un producto baja del mínimo."
                data-vacio-filtrado="Ninguna orden coincide con el filtro.">
                <tr>
                    <td colspan="6" class="text-center text-body-secondary py-4">Cargando…</td>
                </tr>
            </tbody>
        </table>

        <template data-listado-fila>
            <tr>
                <td class="text-nowrap">
                    <a href="{{ route('compras.show', '__ID__') }}" class="fw-semibold link-dark" data-campo="numero"></a>
                    <div class="small text-body-secondary" data-campo="fecha"></div>
                </td>

                <td>
                    <span data-campo="proveedor"></span>
                    {{-- Sin quien la creara, la generó la reposición: no es un dato faltante. --}}
                    <div class="small text-body-secondary" data-generada hidden>
                        <i class="bi bi-robot" aria-hidden="true"></i> Generada automáticamente
                    </div>
                </td>

                <td><span class="badge" data-campo="estado_texto"></span></td>

                <td class="text-center d-none d-md-table-cell" data-campo="productos"></td>

                <td class="text-end text-nowrap" data-campo="total_estimado"></td>

                <td class="text-end text-nowrap">
                    <a href="{{ route('compras.show', '__ID__') }}" class="btn btn-sm btn-outline-dark" data-ver>
                        <i class="bi bi-eye" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Ver</span>
                    </a>
                    {{-- Sólo un borrador se edita: lo dice `editable`, y la API lo vuelve a exigir. --}}
                    @can('compra.editar')
                        <a href="{{ route('compras.edit', '__ID__') }}" hidden data-editar
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
