@extends('layouts.app')

@section('title', 'Proveedores')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Proveedores</h1>
            <p class="text-body-secondary small mb-0">
                El canal de pedido dice cómo llega el pedido a cada proveedor.
        </div>

        @can('proveedor.crear')
            <a href="{{ route('proveedores.create') }}" class="btn btn-acento">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo proveedor
            </a>
        @endcan
    </div>

    @php($hayFiltros = request()->hasAny(['q', 'estado', 'canal']))

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
                                <a href="{{ request()->fullUrlWithoutQuery(['q', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de búsqueda">
                                    <span class="text-secondary fw-normal">Texto:</span>
                                    "{{ Str::limit(request('q'), 18) }}"
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('canal'))
                                <a href="{{ request()->fullUrlWithoutQuery(['canal', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de canal">
                                    <span class="text-secondary fw-normal">Canal:</span>
                                    {{ App\Models\Proveedor::CANALES[request('canal')] ?? request('canal') }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'page']) }}"
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
                <caption class="visually-hidden">Listado de proveedores</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Proveedor</th>
                        <th scope="col">Canal de pedido</th>
                        <th scope="col" class="d-none d-md-table-cell">Tiempo de Entrega</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-center d-none d-sm-table-cell">Productos</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proveedores as $proveedor)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $proveedor->razon_social }}</span>
                                <div class="small text-body-secondary">
                                    CUIT {{ $proveedor->cuitFormateado() }}
                                    @if ($proveedor->contacto)
                                        · {{ $proveedor->contacto }}
                                    @endif
                                </div>
                                {{-- En móvil se oculta la columna, así que el dato baja acá --}}
                                <div class="small text-body-secondary d-sm-none">
                                    {{ $proveedor->productos_count }} producto(s)
                                </div>
                            </td>

                            <td>
                                {{-- El badge lleva texto: ningún estado se comunica sólo con color --}}
                                <span class="badge text-bg-light border">{{ $proveedor->canalPedidoTexto() }}</span>

                                @if ($proveedor->canal_pedido === 'portal_externo' && $proveedor->portal_url)
                                    <a href="{{ $proveedor->portal_url }}" target="_blank" rel="noopener noreferrer"
                                        class="small d-inline-flex align-items-center gap-1 ms-1"
                                        aria-label="Abrir el portal de {{ $proveedor->razon_social }} en una pestaña nueva">
                                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Portal
                                    </a>
                                @elseif ($proveedor->canal_pedido === 'email' && $proveedor->email)
                                    <div class="small text-body-secondary">{{ $proveedor->email }}</div>
                                @endif
                            </td>

                            <td class="d-none d-md-table-cell">
                                @if ($proveedor->plazo_entrega_dias > 0)
                                    ~{{ $proveedor->plazo_entrega_dias }} día(s)
                                @else
                                    <span class="text-body-secondary">No informado</span>
                                @endif
                            </td>

                            <td>
                                <span class="badge {{ $proveedor->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $proveedor->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>

                            <td class="text-center d-none d-sm-table-cell">{{ $proveedor->productos_count }}</td>

                            <td class="text-end text-nowrap">
                                @can('proveedor.editar')
                                    <a href="{{ route('proveedores.edit', $proveedor) }}" class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar {{ $proveedor->razon_social }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan

                                @can('proveedor.eliminar')
                                    <form method="POST" action="{{ route('proveedores.destroy', $proveedor) }}"
                                        class="d-inline" onsubmit="return confirm(@js($proveedor->productos_count > 0 ? "«{$proveedor->razon_social}» tiene {$proveedor->productos_count} producto(s). No se va a eliminar: se desactiva y su historial queda intacto. ¿Continuar?" : "¿Eliminar el proveedor «{$proveedor->razon_social}»?"));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Eliminar el proveedor {{ $proveedor->razon_social }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        {{-- El vacío por filtro y el vacío por falta de datos dicen cosas distintas --}}
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">
                                @if ($hayFiltros)
                                    Ningún proveedor coincide con el filtro.
                                    <a href="{{ route('proveedores.index') }}">Ver todos</a>.
                                @else
                                    Todavía no hay proveedores cargados.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($proveedores->total() > 0)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $proveedores->firstItem() }}–{{ $proveedores->lastItem() }} de {{ $proveedores->total() }}.
            </p>

            {{ $proveedores->links() }}
        </div>
    @endif
@endsection
