@extends('layouts.app')

@use('App\Support\Importe')

@section('title', 'Productos')

@php($hayFiltros = request()->hasAny(['q', 'categoria_id', 'marca_id', 'estado', 'stock']))

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Productos</h1>
            <p class="text-body-secondary small mb-0">
                Un producto con stock o con movimientos registrados no se elimina: se desactiva.
            </p>
        </div>

        @can('producto.crear')
            <a href="{{ route('productos.create') }}" class="btn btn-acento flex-shrink-0">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo producto
            </a>
        @endcan
    </div>

    <form method="GET" action="{{ route('productos.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="q" class="form-label">Buscar por nombre o código</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-muted">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="Por ejemplo: SSD o G505">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="categoria_id" class="form-label">Categoría</label>
                <select class="form-select" id="categoria_id" name="categoria_id" data-buscable="jerarquia">
                    <option value="">Todas</option>
                    <x-opciones-categoria :categorias="$categorias" :seleccionada="request('categoria_id')" />
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="marca_id" class="form-label">Marca</label>
                <select class="form-select" id="marca_id" name="marca_id" data-buscable>
                    <option value="">Todas</option>
                    @foreach ($marcas as $marca)
                        <option value="{{ $marca->id }}" @selected(request('marca_id') == $marca->id)>
                            {{ $marca->nombre }}{{ $marca->activo ? '' : ' (inactiva)' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-6">
                <label for="stock" class="form-label">Stock</label>
                <select class="form-select" id="stock" name="stock">
                    <option value="">Todos</option>
                    <option value="disponible" @selected(request('stock') === 'disponible')>Con stock</option>
                    <option value="agotado" @selected(request('stock') === 'agotado')>Sin stock</option>
                    <option value="critico" @selected(request('stock') === 'critico')>Stock bajo o agotado</option>
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-6">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    <option value="activos" @selected(request('estado') === 'activos')>Activos</option>
                    <option value="inactivos" @selected(request('estado') === 'inactivos')>Inactivos</option>
                </select>
            </div>

            <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('productos.index') }}"
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

                            @if (request()->filled('categoria_id'))
                                <a href="{{ request()->fullUrlWithoutQuery(['categoria_id', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de categoría">
                                    <span class="text-secondary fw-normal">Categoría:</span> {{ $categorias->firstWhere('id', (int) request('categoria_id'))?->nombre ?? request('categoria_id') }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('marca_id'))
                                <a href="{{ request()->fullUrlWithoutQuery(['marca_id', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de marca">
                                    <span class="text-secondary fw-normal">Marca:</span> {{ $marcas->firstWhere('id', (int) request('marca_id'))?->nombre ?? request('marca_id') }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('stock'))
                                <a href="{{ request()->fullUrlWithoutQuery(['stock', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de stock">
                                    <span class="text-secondary fw-normal">Stock:</span> {{ match(request('stock')) { 'disponible' => 'Con stock', 'agotado' => 'Sin stock', 'critico' => 'Stock bajo/agotado', default => request('stock') } }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de estado">
                                    <span class="text-secondary fw-normal">Estado:</span> {{ request('estado') === 'activos' ? 'Activos' : 'Inactivos' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-muted">Mostrando todos los productos</span>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    @if ($hayFiltros)
                        <a href="{{ route('productos.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
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
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">Listado de productos del catálogo</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col" class="d-none d-sm-table-cell" style="width: 64px;">
                        <span class="visually-hidden">Imagen</span>
                    </th>
                    <th scope="col">Producto</th>
                    <th scope="col" class="d-none d-lg-table-cell">Categoría</th>
                    <th scope="col" class="text-end">Precio</th>
                    <th scope="col" class="text-end">Stock</th>
                    <th scope="col" class="d-none d-md-table-cell">Estado</th>
                    <th scope="col" class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($productos as $producto)
                    <tr>
                        <td class="d-none d-sm-table-cell">
                            @if ($producto->imagenes)
                                <img src="{{ Storage::url($producto->imagenes[0]) }}" alt="" width="48"
                                    height="48" class="rounded object-fit-cover">
                            @else
                                <i class="bi bi-box-seam fs-4 text-body-secondary" aria-hidden="true"></i>
                            @endif
                        </td>

                        <td>
                            <span class="fw-semibold">{{ $producto->nombre }}</span>
                            <div class="small text-body-secondary">
                                {{ $producto->codigo }}@if ($producto->marca)
                                    · {{ $producto->marca->nombre }}
                                @endif
                            </div>
                            {{-- Un producto activo colgando de una categoría o marca inactiva no
                                 es un error, pero conviene que se vea: suele ser lo que quedó
                                 pendiente después de discontinuar una línea. --}}
                            @unless ($producto->categoria->activo)
                                <span class="badge text-bg-light border text-warning-emphasis">Categoría inactiva</span>
                            @endunless
                            @if ($producto->marca && !$producto->marca->activo)
                                <span class="badge text-bg-light border text-warning-emphasis">Marca inactiva</span>
                            @endif
                            @unless ($producto->activo)
                                <span class="badge text-bg-secondary d-md-none">Inactivo</span>
                            @endunless
                        </td>

                        <td class="d-none d-lg-table-cell small">{{ $producto->categoria->ruta }}</td>

                        <td class="text-end text-nowrap">
                            {{ Importe::pesos($producto->precio_lista) }}
                            @if ($producto->precio_contado !== $producto->precio_lista)
                                <div class="small text-body-secondary">
                                    Contado {{ Importe::pesos($producto->precio_contado) }}
                                </div>
                            @endif
                        </td>

                        <td class="text-end">
                            {{ $producto->stock_disponible }}
                            {{-- El estado del stock no depende sólo del color: lleva texto. --}}
                            @if ($producto->stock_disponible <= 0)
                                <div><span class="badge text-bg-danger">Sin stock</span></div>
                            @elseif ($producto->stock_disponible <= $producto->stock_minimo)
                                <div><span class="badge text-bg-warning">Stock bajo</span></div>
                            @endif
                        </td>

                        <td class="d-none d-md-table-cell">
                            <span class="badge {{ $producto->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                {{ $producto->activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>

                        <td class="text-end text-nowrap">
                            @can('producto.editar')
                                <a href="{{ route('productos.edit', $producto) }}" class="btn btn-sm btn-outline-dark"
                                    aria-label="Editar {{ $producto->nombre }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                    <span class="d-none d-md-inline">Editar</span>
                                </a>
                            @endcan

                            @can('producto.eliminar')
                                <form method="POST" action="{{ route('productos.destroy', $producto) }}" class="d-inline"
                                    onsubmit="return confirm(@js("¿Eliminar «{$producto->nombre}»? Si tiene stock o movimientos registrados, se va a desactivar en lugar de eliminarse."));">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger"
                                        aria-label="Eliminar {{ $producto->nombre }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">
                                @if ($hayFiltros)
                                    Ningún producto coincide con el filtro.
                                    <a href="{{ route('productos.index') }}">Ver todos</a>.
                                @else
                                    Todavía no hay productos cargados.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($productos->total() > 0)
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3">
                <p class="text-body-secondary small mb-0">
                    Mostrando {{ $productos->firstItem() }}–{{ $productos->lastItem() }} de {{ $productos->total() }}.
                </p>
                {{ $productos->links() }}
            </div>
        @endif
    @endsection
