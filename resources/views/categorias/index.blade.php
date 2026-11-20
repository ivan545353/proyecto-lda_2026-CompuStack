@extends('layouts.app')

@section('title', 'Categorías')

{{-- Shell (regla 37): las filas, la miga de pan y las opciones de «Mostrar» las trae
     pantallas/categorias.js de la API. De la URL sólo se lee lo que el usuario pidió. --}}
@php($grupo = ctype_digit((string) request('parent_id')) ? (int) request('parent_id') : null)

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Categorías</h1>
            <p class="text-body-secondary small mb-0">
                Máximo de {{ \App\Models\Categoria::PROFUNDIDAD_MAXIMA }} niveles. Las que tienen subcategorías
                o productos no se eliminan: se desactivan.
            </p>
        </div>

        @can('categoria.crear')
            <a href="{{ route('categorias.create', $grupo ? ['parent_id' => $grupo] : []) }}"
                class="btn btn-acento flex-shrink-0">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                <span @if ($grupo) data-nueva-en-grupo @endif>{{ $grupo ? 'Nueva subcategoría' : 'Nueva categoría' }}</span>
            </a>
        @endcan
    </div>

    {{-- Miga de pan: dónde estoy en el árbol y cómo vuelvo (visibilidad del
         estado del sistema, y control y libertad del usuario). --}}
    @if ($grupo)
        <nav aria-label="Ubicación dentro de las categorías" class="mb-3" data-miga hidden>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="{{ route('categorias.index') }}">Todas</a>
                </li>
            </ol>
        </nav>
    @endif

    @php($hayFiltros = request()->hasAny(['q', 'parent_id', 'estado']))

    <form method="GET" action="{{ route('categorias.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label for="q" class="form-label">Buscar por nombre</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-muted">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="Por ejemplo: SSD">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-4">
                <label for="parent_id" class="form-label">Mostrar</label>
                <select class="form-select" id="parent_id" name="parent_id" data-buscable="jerarquia"
                    data-buscable-diferido data-opciones-grupos>
                    <option value="">Todas</option>
                    <option value="raiz" @selected(request('parent_id') === 'raiz')>Solo categorías principales</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todas</option>
                    <option value="activas" @selected(request('estado') === 'activas')>Activas</option>
                    <option value="inactivas" @selected(request('estado') === 'inactivas')>Inactivas</option>
                </select>
            </div>

            <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('categorias.index') }}"
                                class="badge rounded-pill text-bg-dark text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                title="Hacé clic para limpiar todos los filtros">
                                <i class="bi bi-funnel-fill text-white-50" aria-hidden="true"></i>
                                <span>Filtros aplicados</span>
                                <i class="bi bi-x-circle-fill text-white-50 ms-1" aria-hidden="true"></i>
                            </a>

                            @if (request()->filled('q'))
                                <a href="{{ request()->fullUrlWithoutQuery(['q', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de búsqueda">
                                    <span class="text-secondary fw-normal">Texto:</span>
                                    "{{ Str::limit(request('q'), 18) }}"
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('parent_id'))
                                <a href="{{ request()->fullUrlWithoutQuery(['parent_id', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de jerarquía">
                                    <span class="text-secondary fw-normal">Mostrar:</span>
                                    @if (request('parent_id') === 'raiz')
                                        Solo principales
                                    @else
                                        Subcategorías de <span data-nombre-grupo>la elegida</span>
                                    @endif
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de estado">
                                    <span class="text-secondary fw-normal">Estado:</span>
                                    {{ request('estado') === 'activas' ? 'Activas' : 'Inactivas' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-muted">Mostrando todas las categorías</span>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    @if ($hayFiltros)
                        <a href="{{ route('categorias.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <x-imprimir api="/categorias/impresion" :listado="new \App\Impresion\ListadoCategorias()" />
                    <button type="submit" class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- La tabla llega vacía: las filas las pide pantallas/categorias.js a la API (regla 37). --}}
    <div data-pantalla="categorias">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Listado de categorías del catálogo</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Nombre</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-center">Subcategorías</th>
                            <th scope="col" class="text-center d-none d-md-table-cell">Productos</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="5"
                        data-vacio="{{ $grupo ? 'Esta categoría no tiene subcategorías.' : 'Todavía no hay categorías cargadas.' }}"
                        data-vacio-filtrado="Ninguna categoría coincide con el filtro.">
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Una fila, con un @can por acción (regla 12). Los textos los pone el JS con textContent. --}}
        <template data-listado-fila>
            <tr>
                <td>
                    <span class="fw-semibold" data-campo="nombre"></span>
                    {{-- La ruta completa sólo si no está a la vista en la miga de pan. --}}
                    <div class="text-body-secondary small" data-campo="dentro-de"></div>
                </td>
                <td><span class="badge" data-campo="estado"></span></td>
                <td class="text-center">
                    <a href="?parent_id=__ID__" class="link-dark fw-semibold" data-entrar>
                        <span data-campo="subcategorias"></span> <i class="bi bi-chevron-right small" aria-hidden="true"></i>
                    </a>
                    <span data-sin-subcategorias>
                        <span class="text-body-secondary" aria-hidden="true">—</span>
                        <span class="visually-hidden">Ninguna</span>
                    </span>
                </td>
                <td class="text-center d-none d-md-table-cell" data-campo="productos"></td>
                <td class="text-end text-nowrap">
                    @can('categoria.editar')
                        <a href="{{ route('categorias.edit', ['categoria' => '__ID__']) }}" data-accion="editar"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-md-inline">Editar</span>
                        </a>
                    @endcan

                    {{-- Baja física o lógica: el aviso previo dice cuál va a ser, según lo que tenga adentro. --}}
                    @can('categoria.eliminar')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/categorias/__ID__">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    @endcan
                </td>
            </tr>
        </template>

        {{-- Resumen de resultados y paginado: los arma el JS con el sobre de la API. --}}
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3"
            data-listado-pie hidden>
            <p class="text-body-secondary small mb-0" data-listado-resumen></p>
            <div data-listado-paginas></div>
        </div>
    </div>
@endsection
