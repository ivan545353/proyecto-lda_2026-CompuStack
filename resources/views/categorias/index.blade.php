@extends('layouts.app')

@section('title', 'Categorías')

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
            <a href="{{ route('categorias.create', $padreFiltrado ? ['parent_id' => $padreFiltrado->id] : []) }}"
                class="btn btn-acento flex-shrink-0">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                {{ $padreFiltrado ? "Nueva subcategoría de {$padreFiltrado->nombre}" : 'Nueva categoría' }}
            </a>
        @endcan
    </div>

    {{-- Miga de pan: dónde estoy en el árbol y cómo vuelvo (visibilidad del
         estado del sistema, y control y libertad del usuario). --}}
    @if ($padreFiltrado)
        <nav aria-label="Ubicación dentro de las categorías" class="mb-3">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item">
                    <a href="{{ route('categorias.index') }}">Todas</a>
                </li>
                @foreach (collect([$padreFiltrado->padre?->padre, $padreFiltrado->padre])->filter() as $ancestro)
                    <li class="breadcrumb-item">
                        <a
                            href="{{ route('categorias.index', ['parent_id' => $ancestro->id]) }}">{{ $ancestro->nombre }}</a>
                    </li>
                @endforeach
                <li class="breadcrumb-item active" aria-current="page">{{ $padreFiltrado->nombre }}</li>
            </ol>
        </nav>
    @endif

    <form method="GET" action="{{ route('categorias.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="q" class="form-label">Buscar por nombre</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ request('q') }}"
                    placeholder="Por ejemplo: SSD">
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="parent_id" class="form-label">Mostrar</label>
                <select class="form-select" id="parent_id" name="parent_id" data-buscable="jerarquia">
                    <option value="">Todas</option>
                    <option value="raiz" @selected(request('parent_id') === 'raiz')>Solo categorías principales</option>
                    <x-opciones-categoria :categorias="$padresConHijas" :seleccionada="request('parent_id')" prefijo="Subcategorías de " />
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-2">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todas</option>
                    <option value="activas" @selected(request('estado') === 'activas')>Activas</option>
                    <option value="inactivas" @selected(request('estado') === 'inactivas')>Inactivas</option>
                </select>
            </div>

            <div class="col-12 col-md-3 d-grid d-sm-flex gap-2">
                <button type="submit" class="btn btn-outline-dark">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                </button>

                @if (request()->hasAny(['q', 'parent_id', 'estado']))
                    <a href="{{ route('categorias.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
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
            <tbody>
                @forelse ($categorias as $categoria)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $categoria->nombre }}</span>
                            {{-- La ruta completa sólo si no está a la vista en la miga de pan. --}}
                            @if ($categoria->padre && !$padreFiltrado)
                                <div class="text-body-secondary small">{{ $categoria->padre->ruta }}</div>
                            @endif
                        </td>

                        <td>
                            <span class="badge {{ $categoria->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                {{ $categoria->activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>

                        <td class="text-center">
                            @if ($categoria->hijas_count > 0)
                                <a href="{{ route('categorias.index', ['parent_id' => $categoria->id]) }}"
                                    class="link-dark fw-semibold"
                                    aria-label="Ver las {{ $categoria->hijas_count }} subcategorías de {{ $categoria->nombre }}">
                                    {{ $categoria->hijas_count }} <i class="bi bi-chevron-right small"
                                        aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="text-body-secondary" aria-hidden="true">—</span>
                                <span class="visually-hidden">Ninguna</span>
                            @endif
                        </td>

                        <td class="text-center d-none d-md-table-cell">{{ $categoria->productos_count }}</td>

                        <td class="text-end text-nowrap">
                            @can('categoria.editar')
                                <a href="{{ route('categorias.edit', $categoria) }}" class="btn btn-sm btn-outline-dark"
                                    aria-label="Editar {{ $categoria->nombre }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                    <span class="d-none d-md-inline">Editar</span>
                                </a>
                            @endcan

                            @can('categoria.eliminar')
                                @php($dependencias = $categoria->hijas_count + $categoria->productos_count)
                                <form method="POST" action="{{ route('categorias.destroy', $categoria) }}" class="d-inline"
                                    onsubmit="return confirm(@js($dependencias > 0 ? "«{$categoria->nombre}» tiene {$categoria->hijas_count} subcategoría(s) y {$categoria->productos_count} producto(s). No se eliminará: se desactivará. ¿Continuar?" : "¿Eliminar la categoría «{$categoria->nombre}»?"));">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"
                                        aria-label="Eliminar {{ $categoria->nombre }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-body-secondary py-4">
                            @if (request()->hasAny(['q', 'estado']))
                                Ninguna categoría coincide con el filtro.
                                <a href="{{ route('categorias.index') }}">Ver todas</a>.
                            @elseif ($padreFiltrado)
                                «{{ $padreFiltrado->nombre }}» no tiene subcategorías.
                            @else
                                Todavía no hay categorías cargadas.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($categorias->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $categorias->firstItem() }}–{{ $categorias->lastItem() }} de {{ $categorias->total() }}.
            </p>

            {{ $categorias->links() }}
        </div>
    @endif
@endsection
