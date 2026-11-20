@extends('layouts.app')

@section('title', 'Productos')

{{-- Shell (regla 37): las filas y las opciones de los filtros las trae pantallas/productos.js de la
     API. De la URL sólo se lee lo que el usuario pidió. Cada filtro que lista otro módulo se ofrece
     a quien puede ver ese módulo: sus opciones salen de su propio endpoint (C-2). --}}
@php($hayFiltros = request()->hasAny(['q', 'categoria_id', 'marca_id', 'proveedor_id', 'estado', 'stock']))
@php($puedeAjustar = auth()->user()->can('precio.ajustar_masivo'))
@php($veCosto = auth()->user()->can('precio.ver_costo'))
@php($columnas = 7 + ($veCosto ? 1 : 0) + ($puedeAjustar ? 1 : 0))
@php($chip = 'badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1')

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

            @can('categoria.ver')
                <div class="col-12 col-sm-6 col-lg-4">
                    <label for="categoria_id" class="form-label">Categoría</label>
                    <select class="form-select" id="categoria_id" name="categoria_id" data-buscable="jerarquia"
                        data-buscable-diferido data-opciones="categorias" data-seleccionada="{{ request('categoria_id') }}">
                        <option value="">Todas</option>
                    </select>
                </div>
            @endcan

            @can('marca.ver')
                <div class="col-12 col-sm-6 col-lg-4">
                    <label for="marca_id" class="form-label">Marca</label>
                    <select class="form-select" id="marca_id" name="marca_id" data-buscable data-buscable-diferido
                        data-opciones="marcas" data-seleccionada="{{ request('marca_id') }}">
                        <option value="">Todas</option>
                    </select>
                </div>
            @endcan

            @can('proveedor.ver')
                <div class="col-12 col-sm-6 col-lg-4">
                    <label for="proveedor_id" class="form-label">Proveedor</label>
                    <select class="form-select" id="proveedor_id" name="proveedor_id" data-buscable data-buscable-diferido
                        data-opciones="proveedores" data-seleccionada="{{ request('proveedor_id') }}">
                        <option value="">Todos</option>
                    </select>
                </div>
            @endcan

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
                                <a href="{{ request()->fullUrlWithoutQuery(['q', 'pagina']) }}" class="{{ $chip }}"
                                    title="Quitar filtro de búsqueda">
                                    <span class="text-secondary fw-normal">Texto:</span>
                                    "{{ Str::limit(request('q'), 18) }}"
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            {{-- El nombre lo pone el JS con las opciones del filtro, que vienen de la API. --}}
                            @foreach (['categoria_id' => 'Categoría', 'marca_id' => 'Marca', 'proveedor_id' => 'Proveedor'] as $clave => $rotulo)
                                @if (request()->filled($clave))
                                    <a href="{{ request()->fullUrlWithoutQuery([$clave, 'pagina']) }}" class="{{ $chip }}"
                                        title="Quitar filtro de {{ Str::lower($rotulo) }}">
                                        <span class="text-secondary fw-normal">{{ $rotulo }}:</span>
                                        <span data-nombre-filtro="{{ $clave }}">la elegida</span>
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif
                            @endforeach

                            @if (request()->filled('stock'))
                                <a href="{{ request()->fullUrlWithoutQuery(['stock', 'pagina']) }}" class="{{ $chip }}"
                                    title="Quitar filtro de stock">
                                    <span class="text-secondary fw-normal">Stock:</span>
                                    {{ match (request('stock')) {'disponible' => 'Con stock', 'agotado' => 'Sin stock', 'critico' => 'Stock bajo/agotado', 'sin_proveedor' => 'Para reponer y sin proveedor', default => request('stock')} }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'pagina']) }}" class="{{ $chip }}"
                                    title="Quitar filtro de estado">
                                    <span class="text-secondary fw-normal">Estado:</span>
                                    {{ request('estado') === 'activos' ? 'Activos' : 'Inactivos' }}
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
                    <x-imprimir api="/productos/impresion" :listado="new \App\Impresion\ListadoProductos()" />
                    <button type="submit" class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- La tabla llega vacía: las filas las pide pantallas/productos.js a la API (regla 37). --}}
    <div data-pantalla="productos">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Listado de productos del catálogo</caption>
                    <thead class="table-light">
                        <tr>
                            @if ($puedeAjustar)
                                <th scope="col" style="width: 40px;">
                                    <input class="form-check-input casilla-fila mt-0" type="checkbox"
                                        data-seleccion-todas="formSeleccionAjuste"
                                        aria-label="Marcar todos los productos de esta página">
                                </th>
                            @endif
                            <th scope="col" class="d-none d-sm-table-cell" style="width: 64px;">
                                <span class="visually-hidden">Imagen</span>
                            </th>
                            <th scope="col">Producto</th>
                            <th scope="col" class="d-none d-lg-table-cell">Categoría</th>
                            <th scope="col" class="text-end">Precio</th>
                            @if ($veCosto)
                                <th scope="col" class="text-end d-none d-xl-table-cell">Costo</th>
                            @endif
                            <th scope="col" class="text-end">Stock</th>
                            <th scope="col" class="d-none d-md-table-cell">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="{{ $columnas }}" data-ver-todos="Ver todos"
                        data-vacio="Todavía no hay productos cargados."
                        data-vacio-filtrado="Ningún producto coincide con el filtro.">
                        <tr>
                            <td colspan="{{ $columnas }}" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Una fila, con un @can por acción (regla 12). Los textos los pone el JS con textContent. --}}
        <template data-listado-fila>
            <tr>
                @if ($puedeAjustar)
                    <td>
                        <input class="form-check-input casilla-fila mt-0" type="checkbox" name="ids[]" value="__ID__"
                            form="formSeleccionAjuste" data-marcar>
                    </td>
                @endif

                <td class="d-none d-sm-table-cell">
                    <img alt="" width="48" height="48" class="rounded object-fit-cover" data-imagen hidden>
                    <i class="bi bi-box-seam fs-4 text-body-secondary" aria-hidden="true" data-sin-imagen></i>
                </td>

                <td>
                    <span class="fw-semibold" data-campo="nombre"></span>
                    <div class="small text-body-secondary" data-campo="detalle"></div>
                    {{-- Un producto activo colgando de una categoría o marca inactiva no es un error, pero
                         conviene que se vea: suele ser lo que quedó pendiente al discontinuar una línea. --}}
                    <span class="badge text-bg-light border text-warning-emphasis" data-aviso="categoria-inactiva" hidden>Categoría inactiva</span>
                    <span class="badge text-bg-light border text-warning-emphasis" data-aviso="marca-inactiva" hidden>Marca inactiva</span>
                    <span class="badge text-bg-warning" data-aviso="sin-proveedor" hidden>Sin proveedor</span>
                    <span class="badge text-bg-secondary d-md-none" data-aviso="inactivo" hidden>Inactivo</span>
                </td>

                <td class="d-none d-lg-table-cell small" data-campo="categoria"></td>

                {{-- El mostrador cotiza con el precio de contado: es el número que se decide. --}}
                <td class="text-end text-nowrap">
                    <span data-campo="contado"></span>
                    <div class="small text-body-secondary" data-lista hidden>Lista <span data-campo="lista"></span></div>
                    <div data-a-mano hidden>
                        <span class="badge text-bg-light border text-body-secondary"
                            title="Precio fijado a mano: el recálculo y el ajuste masivo no lo cambian.">A mano</span>
                    </div>
                </td>

                @if ($veCosto)
                    <td class="text-end text-nowrap d-none d-xl-table-cell">
                        <span data-campo="costo"></span>
                        <div class="small text-body-secondary" data-margen hidden>Margen <span data-campo="margen"></span> %</div>
                    </td>
                @endif

                {{-- El estado del stock no depende sólo del color: lleva texto. --}}
                <td class="text-end">
                    <span data-campo="disponible"></span>
                    <div data-sin-stock hidden><span class="badge text-bg-danger">Sin stock</span></div>
                    <div data-stock-bajo hidden><span class="badge text-bg-warning">Stock bajo</span></div>
                </td>

                <td class="d-none d-md-table-cell"><span class="badge" data-campo="estado"></span></td>

                <td class="text-end text-nowrap">
                    @can('stock.ver')
                        <a href="{{ route('stock.index', ['producto_id' => '__ID__']) }}" class="btn btn-sm btn-outline-dark"
                            data-accion="stock">
                            <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                            <span class="d-none d-lg-inline">Stock</span>
                        </a>
                    @endcan

                    @can('producto.editar')
                        <a href="{{ route('producto-proveedores.index', ['producto' => '__ID__']) }}"
                            class="btn btn-sm btn-outline-dark" data-accion="proveedores">
                            <i class="bi bi-truck" aria-hidden="true"></i>
                            <span class="d-none d-xl-inline">Proveedores</span>
                        </a>
                    @endcan

                    @can('producto.editar')
                        <a href="{{ route('productos.edit', ['producto' => '__ID__']) }}" class="btn btn-sm btn-outline-dark"
                            data-accion="editar">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-md-inline">Editar</span>
                        </a>
                    @endcan

                    @can('producto.eliminar')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/productos/__ID__">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    @endcan
                </td>
            </tr>
        </template>

        {{-- Se muestra cuando hay filas: lo decide el JS. --}}
        @if ($puedeAjustar)
            <form method="GET" action="{{ route('precios.ajuste') }}" id="formSeleccionAjuste" data-seleccion
                data-seleccion-diferida hidden
                class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mt-3">
                <p class="text-body-secondary small mb-0" data-seleccion-contador
                    data-seleccion-marcadas="{n} producto(s) marcados en esta página.">
                    Marcá los productos de esta página que querés ajustar.
                </p>

                <button type="submit" id="ajustarSeleccionados" class="btn btn-outline-dark text-nowrap">
                    <i class="bi bi-percent" aria-hidden="true"></i> Ajustar los precios seleccionados
                </button>
            </form>
        @endif

        {{-- Resumen de resultados y paginado: los arma el JS con el sobre de la API. --}}
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3"
            data-listado-pie hidden>
            <p class="text-body-secondary small mb-0" data-listado-resumen></p>
            <div data-listado-paginas></div>
        </div>
    </div>
@endsection
