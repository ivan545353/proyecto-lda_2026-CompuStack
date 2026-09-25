@extends('layouts.app')

@section('title', 'Marcas')

@section('content')
    {{-- Encabezado del módulo y acción de alta --}}
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Marcas</h1>
            <p class="text-body-secondary small mb-0">
                Las marcas con productos asociados no se eliminan: se desactivan.
            </p>
        </div>

        @can('marca.crear')
            <a href="{{ route('marcas.create') }}" class="btn btn-acento">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva marca
            </a>
        @endcan
    </div>

    @php($hayFiltros = request()->hasAny(['q', 'estado']))

    {{-- Formulario de búsqueda y filtros (GET para preservar estado en URL) --}}
    <form method="GET" action="{{ route('marcas.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            {{-- Búsqueda por coincidencia de texto en nombre --}}
            <div class="col-12 col-md-7">
                <label for="q" class="form-label">Buscar por nombre</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-muted">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q" value="{{ request('q') }}"
                        placeholder="Por ejemplo: Kingston">
                </div>
            </div>

            {{-- Filtro por estado activo/inactivo --}}
            <div class="col-12 col-md-5">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todas</option>
                    <option value="activas" @selected(request('estado') === 'activas')>Activas</option>
                    <option value="inactivas" @selected(request('estado') === 'inactivas')>Inactivas</option>
                </select>
            </div>

            {{-- Botones de acción del filtro y feedback --}}
            <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('marcas.index') }}"
                                class="badge rounded-pill text-bg-dark text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                title="Hacé clic para limpiar todos los filtros">
                                <i class="bi bi-funnel-fill text-white-50" aria-hidden="true"></i>
                                <span>Filtros aplicados</span>
                                <i class="bi bi-x-circle-fill text-white-50 ms-1" aria-hidden="true"></i>
                            </a>

                            @if (request()->filled('q'))
                                <a href="{{ request()->fullUrlWithoutQuery(['q', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de búsqueda">
                                    <span class="text-secondary fw-normal">Texto:</span> "{{ Str::limit(request('q'), 18) }}"
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de estado">
                                    <span class="text-secondary fw-normal">Estado:</span> {{ request('estado') === 'activas' ? 'Activas' : 'Inactivas' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-muted">Mostrando todas las marcas</span>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    @if ($hayFiltros)
                        <a href="{{ route('marcas.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <button type="submit" class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Listado de marcas del catálogo</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width: 64px;">Logo</th>
                        <th scope="col">Nombre</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-center d-none d-sm-table-cell">Productos</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($marcas as $marca)
                        <tr>
                            {{-- Logo corporativo o icono de respaldo --}}
                            <td>
                                @if ($marca->logo)
                                    <img src="{{ Storage::url($marca->logo) }}" alt="" height="32"
                                        class="object-fit-contain">
                                @else
                                    <i class="bi bi-tag text-body-secondary fs-5" aria-hidden="true"></i>
                                @endif
                            </td>

                            {{-- Nombre y slug único --}}
                            <td>
                                <span class="fw-semibold">{{ $marca->nombre }}</span>
                                <div class="clave-permiso d-inline-block ms-1">{{ $marca->slug }}</div>
                                <div class="small text-body-secondary d-sm-none">{{ $marca->productos_count }} producto(s)</div>
                            </td>

                            {{-- Estado (accesible: comunica con texto y no solo con color) --}}
                            <td>
                                <span class="badge {{ $marca->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $marca->activo ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>

                            {{-- Cantidad de productos asociados (withCount) --}}
                            <td class="text-center d-none d-sm-table-cell">{{ $marca->productos_count }}</td>

                            {{-- Acciones contextuales según permisos --}}
                            <td class="text-end text-nowrap">
                                @can('marca.editar')
                                    <a href="{{ route('marcas.edit', $marca) }}" class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar {{ $marca->nombre }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan

                                {{-- Baja física o lógica con mensaje preventivo según tenga productos --}}
                                @can('marca.eliminar')
                                    <form method="POST" action="{{ route('marcas.destroy', $marca) }}" class="d-inline"
                                        onsubmit="return confirm(@js($marca->productos_count > 0 ? "«{$marca->nombre}» tiene {$marca->productos_count} producto(s). No se eliminará: se desactivará. ¿Continuar?" : "¿Eliminar la marca «{$marca->nombre}»?"));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Eliminar la marca {{ $marca->nombre }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        {{-- Estado vacío con distinción según si hay filtros aplicados --}}
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                @if (request()->hasAny(['q', 'estado']))
                                    Ninguna marca coincide con el filtro.
                                    <a href="{{ route('marcas.index') }}">Ver todas</a>.
                                @else
                                    Todavía no hay marcas cargadas.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Resumen de resultados y controles de paginación --}}
    @if ($marcas->total() > 0)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $marcas->firstItem() }}–{{ $marcas->lastItem() }} de {{ $marcas->total() }}.
            </p>

            {{ $marcas->links() }}
        </div>
    @endif
@endsection
