@extends('layouts.app')

@section('title', 'Proveedores')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Proveedores</h1>
            <p class="text-body-secondary small mb-0">
                El canal de pedido dice cómo llega el pedido a cada proveedor.
            </p>
        </div>

        @can('proveedor.crear')
            <a href="{{ route('proveedores.create') }}" class="btn btn-acento">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo proveedor
            </a>
        @endcan
    </div>

    @php
        $hayFiltros = request()->hasAny(['q', 'estado', 'canal']);
    @endphp

    {{-- Filtros por GET: la pantalla queda enlazable y el botón "atrás" funciona --}}
    <form method="GET" action="{{ route('proveedores.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-6">
                <label for="q" class="form-label">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-body-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="Nombre, CUIT, contacto o correo"
                        aria-describedby="ayudaBuscar">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="canal" class="form-label">Canal de pedido</label>
                <select class="form-select" id="canal" name="canal">
                    <option value="">Todos</option>
                    @foreach (App\Models\Proveedor::CANALES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('canal') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
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
                            <a href="{{ route('proveedores.index') }}"
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

                            @if (request()->filled('canal'))
                                <a href="{{ request()->fullUrlWithoutQuery(['canal', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de canal">
                                    <span class="text-secondary fw-normal">Canal:</span>
                                    {{ App\Models\Proveedor::CANALES[request('canal')] ?? request('canal') }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'pagina']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de estado">
                                    <span class="text-secondary fw-normal">Estado:</span>
                                    {{ request('estado') === 'activos' ? 'Activos' : 'Inactivos' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-body-secondary">Mostrando todos los proveedores</span>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    @if ($hayFiltros)
                        <a href="{{ route('proveedores.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <x-imprimir api="/proveedores/impresion" :listado="new \App\Impresion\ListadoProveedores()" />

                    <button type="submit" class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- La tabla llega vacía: las filas las pide pantallas/proveedores.js a la API (regla 37). --}}
    <div data-pantalla="proveedores">
        <div class="card border-0 shadow-sm">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Listado de proveedores</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Proveedor</th>
                        <th scope="col">Canal de pedido</th>
                        {{-- Dos columnas, dos preguntas. Antes «Vinculado a» mostraba los dos
                             números y «Productos» repetía el primero --}}
                        <th scope="col" class="text-center d-none d-sm-table-cell">Productos</th>
                        <th scope="col" class="text-center d-none d-sm-table-cell">Órdenes</th>
                        <th scope="col" class="d-none d-md-table-cell">Tiempo de Entrega</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody data-listado-filas data-columnas="7" data-vacio="Todavía no hay proveedores cargados."
                    data-vacio-filtrado="Ningún proveedor coincide con el filtro." data-ver-todos="Ver todos">
                    <tr>
                        <td colspan="7" class="text-center text-body-secondary py-4">Cargando…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Una fila, con un @can por acción (regla 12). Los textos los pone el JS con textContent.
             Un número se enlaza al catálogo o a las compras filtrados sólo con el permiso de ese
             listado, que sin él devolvería un 403; un cero no se enlaza. --}}
        <template data-listado-fila>
            <tr>
                <td>
                    <span class="fw-semibold" data-campo="razon_social"></span>
                    <div class="small text-body-secondary" data-campo="detalle"></div>
                    {{-- En móvil las dos columnas se ocultan, así que los números bajan acá con los mismos enlaces --}}
                    <div class="small d-sm-none">
                        <span data-cantidad="productos">
                            @can('producto.ver')
                                <a href="{{ route('productos.index', ['proveedor_id' => '__ID__']) }}" hidden></a>
                            @endcan
                            <span data-plano hidden></span>
                            <span class="text-body-secondary" data-cero>Sin productos</span>
                        </span>
                        <span data-cantidad="ordenes" data-solo-con-cantidad hidden>
                            ·
                            @can('compra.ver')
                                <a href="{{ route('compras.index', ['proveedor_id' => '__ID__']) }}" hidden></a>
                            @endcan
                            <span data-plano hidden></span>
                        </span>
                    </div>
                </td>

                <td>
                    {{-- El badge lleva texto: ningún estado se comunica sólo con color --}}
                    <span class="badge text-bg-light border" data-campo="canal"></span>
                    <a href="#" target="_blank" rel="noopener noreferrer" data-portal hidden
                        class="small d-inline-flex align-items-center gap-1 ms-1">
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Portal
                    </a>
                    <div class="small text-body-secondary" data-campo="correo" hidden></div>
                </td>

                <td class="text-center d-none d-sm-table-cell" data-cantidad="productos">
                    @can('producto.ver')
                        <a href="{{ route('productos.index', ['proveedor_id' => '__ID__']) }}" hidden></a>
                    @endcan
                    <span data-plano hidden></span>
                    <span class="text-body-secondary" data-cero title="No se le compra ningún producto">—</span>
                </td>

                <td class="text-center d-none d-sm-table-cell" data-cantidad="ordenes">
                    @can('compra.ver')
                        <a href="{{ route('compras.index', ['proveedor_id' => '__ID__']) }}" hidden></a>
                    @endcan
                    <span data-plano hidden></span>
                    <span class="text-body-secondary" data-cero title="Todavía no se le hizo ningún pedido">—</span>
                </td>

                <td class="d-none d-md-table-cell" data-campo="plazo"></td>

                <td><span class="badge" data-campo="estado"></span></td>

                <td class="text-end text-nowrap">
                    @can('proveedor.editar')
                        <a href="{{ route('proveedores.edit', ['proveedor' => '__ID__']) }}" data-accion="editar"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Editar</span>
                        </a>
                    @endcan

                    {{-- Baja física o lógica: el aviso previo dice cuál va a ser, según esté referenciado. --}}
                    @can('proveedor.eliminar')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/proveedores/__ID__">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    @endcan
                </td>
            </tr>
        </template>

        {{-- Resumen de resultados y paginado: los arma el JS con el sobre de la API. --}}
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3" data-listado-pie hidden>
            <p class="text-body-secondary small mb-0" data-listado-resumen></p>
            <div data-listado-paginas></div>
        </div>
    </div>
@endsection
