@extends('layouts.app')

@use('App\Support\Importe')

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

    @php
        $hayFiltros = request()->hasAny(['q', 'estado', 'proveedor_id', 'desde', 'hasta']);
        $proveedorFiltrado = $proveedores->firstWhere('id', request()->integer('proveedor_id'));

        $chips = collect([
            'q' => request('q') ? 'Texto: "' . Str::limit(request('q'), 18) . '"' : null,
            'estado' => request('estado')
                ? 'Estado: ' . (App\Models\OrdenCompra::ESTADOS[request('estado')] ?? request('estado'))
                : null,
            'proveedor_id' => $proveedorFiltrado
                ? 'Proveedor: ' . Str::limit($proveedorFiltrado->razon_social, 22)
                : null,
            'desde' => request('desde') ? 'Desde: ' . request('desde') : null,
            'hasta' => request('hasta') ? 'Hasta: ' . request('hasta') : null,
        ])->filter();
    @endphp

    <form method="GET" action="{{ route('compras.index') }}" class="card card-body border-0 shadow-sm mb-4">
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
                <select class="form-select" id="proveedor_id" name="proveedor_id" data-buscable>
                    <option value="">Todos</option>
                    @foreach ($proveedores as $opcion)
                        <option value="{{ $opcion->id }}" @selected(request()->integer('proveedor_id') === $opcion->id)>
                            {{ $opcion->razon_social }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    @foreach (App\Models\OrdenCompra::ESTADOS as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('estado') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6">
                <label for="desde" class="form-label">Creada desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="{{ request('desde') }}">
            </div>

            <div class="col-12 col-sm-6">
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
                                <a href="{{ request()->fullUrlWithoutQuery([$parametro, 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar este filtro">
                                    {{ $texto }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endforeach
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
                    <th scope="col" class="text-center d-none d-md-table-cell" style="min-width: 5.5rem;">Productos</th>
                    <th scope="col" class="text-end" style="min-width: 8rem;">Total estimado</th>
                    <th scope="col" class="text-end" style="min-width: 5.5rem;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($ordenes as $orden)
                    <tr>
                        <td class="text-nowrap">
                            @can('compra.ver')
                                <a href="{{ route('compras.show', $orden) }}" class="fw-semibold link-dark">
                                    {{ $orden->numeroFormateado() }}
                                </a>
                            @else
                                <span class="fw-semibold">{{ $orden->numeroFormateado() }}</span>
                            @endcan
                            <div class="small text-body-secondary">
                                {{ $orden->created_at->format('d/m/Y') }}
                            </div>
                        </td>

                        <td>
                            {{ $orden->proveedor->razon_social }}
                            @if ($orden->fueGeneradaPorElSistema())
                                {{-- usuario_creo_id en null SIGNIFICA esto: no es un dato faltante --}}
                                <div class="small text-body-secondary">
                                    <i class="bi bi-robot" aria-hidden="true"></i> Generada automáticamente
                                </div>
                            @endif
                        </td>

                        <td>
                            <span class="badge {{ $orden->estadoClase() }}">{{ $orden->estadoTexto() }}</span>
                        </td>

                        <td class="text-center d-none d-md-table-cell">{{ $orden->lineas_count }}</td>

                        <td class="text-end text-nowrap">{{ Importe::pesos($orden->total_estimado) }}</td>

                        <td class="text-end text-nowrap">

                            @can('compra.ver')
                                <a href="{{ route('compras.show', $orden) }}" class="btn btn-sm btn-outline-dark"
                                    aria-label="Ver la orden {{ $orden->numeroFormateado() }}">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                    <span class="d-none d-sm-inline">Ver</span>
                                </a>
                            @endcan
                            {{-- Sólo un borrador se edita. No ofrecer el botón es
                                    la mitad del trabajo; la otra la hace el controlador. --}}
                            @if ($orden->esEditable())
                                @can('compra.editar')
                                    <a href="{{ route('compras.edit', $orden) }}"
                                        class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1"
                                        aria-label="Editar la orden {{ $orden->numeroFormateado() }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-body-secondary py-4">
                            @if ($hayFiltros)
                                Ninguna orden coincide con el filtro.
                                <a href="{{ route('compras.index') }}">Ver todas</a>.
                            @else
                                Todavía no hay órdenes de compra. Se cargan a mano, o las genera
                                la tarea de reposición cuando un producto baja del mínimo.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($ordenes->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0 text-center text-sm-start">
                Mostrando {{ $ordenes->firstItem() }}–{{ $ordenes->lastItem() }} de {{ $ordenes->total() }}.
            </p>

            <div class="d-flex justify-content-center">
                {{ $ordenes->links() }}
            </div>
        </div>
    @endif
@endsection
