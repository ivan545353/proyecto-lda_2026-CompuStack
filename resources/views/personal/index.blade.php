@extends('layouts.app')

@section('title', 'Usuarios')

{{-- Shell (regla 37): las filas y las opciones de rol las pide pantallas/personal.js a la API. Lo que viene
     de la URL es lo que el operador eligió, no datos de negocio. --}}
@php
    $hayFiltros = request()->hasAny(['q', 'rol_id', 'estado', 'situacion']);
    $chip = 'badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1';
@endphp

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Personal</h1>
            <p class="text-body-secondary small mb-0">
                Las cuentas de quienes trabajan en el sistema de gestión. Las fichas de clientes
                están en Usuarios → Clientes.
            </p>
        </div>

        @can('usuario.crear')
            <a href="{{ route('personal.create') }}" class="btn btn-acento">
                <i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo Integrante
            </a>
        @endcan
    </div>

    <div data-pantalla="personal">
        {{-- Filtros por GET: la pantalla queda enlazable y el botón atrás funciona. Un filtro inválido lo
             marca el módulo central en su campo (422). --}}
        <form method="GET" action="{{ route('personal.index') }}" novalidate data-filtros-personal
            class="card card-body border-0 shadow-sm mb-4">
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="q" class="form-label">Buscar</label>
                    <div class="input-group">
                        <span class="input-group-text bg-body-tertiary border-end-0 text-muted">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                            value="{{ request('q') }}" placeholder="Nombre, apellido, correo o legajo">
                    </div>
                </div>

                {{-- Las opciones son los roles del personal: las pide el JS. --}}
                <div class="col-6 col-md-4 col-lg-3">
                    <label for="rol_id" class="form-label">Rol</label>
                    <select class="form-select" id="rol_id" name="rol_id" data-buscable data-buscable-diferido
                        data-seleccionada="{{ request('rol_id') }}">
                        <option value="">Todos</option>
                    </select>
                </div>

                <div class="col-6 col-md-4 col-lg-3">
                    <label for="estado" class="form-label">Acceso</label>
                    <select class="form-select" id="estado" name="estado">
                        <option value="">Todos</option>
                        <option value="activos" @selected(request('estado') === 'activos')>Con acceso</option>
                        <option value="inactivos" @selected(request('estado') === 'inactivos')>Sin acceso</option>
                    </select>
                </div>

                <div class="col-6 col-md-4 col-lg-3">
                    <label for="situacion" class="form-label">Situación laboral</label>
                    <select class="form-select" id="situacion" name="situacion">
                        <option value="">Todas</option>
                        <option value="en_actividad" @selected(request('situacion') === 'en_actividad')>En actividad</option>
                        <option value="dados_de_baja" @selected(request('situacion') === 'dados_de_baja')>Dados de baja</option>
                    </select>
                </div>

                <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                    <div class="text-body-secondary small">
                        @if ($hayFiltros)
                            <div class="d-inline-flex align-items-center flex-wrap gap-1">
                                <a href="{{ route('personal.index') }}"
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

                                {{-- El nombre del rol lo pone el JS, con lo que trae la API. --}}
                                @if (request()->filled('rol_id'))
                                    <a href="{{ request()->fullUrlWithoutQuery(['rol_id', 'pagina']) }}" class="{{ $chip }}"
                                        title="Quitar filtro de rol">
                                        <span class="text-secondary fw-normal">Rol:</span>
                                        <span data-nombre-filtro="rol_id">el elegido</span>
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @if (request()->filled('estado'))
                                    <a href="{{ request()->fullUrlWithoutQuery(['estado', 'pagina']) }}" class="{{ $chip }}"
                                        title="Quitar filtro de acceso">
                                        <span class="text-secondary fw-normal">Acceso:</span>
                                        {{ request('estado') === 'activos' ? 'Con acceso' : 'Sin acceso' }}
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @if (request()->filled('situacion'))
                                    <a href="{{ request()->fullUrlWithoutQuery(['situacion', 'pagina']) }}" class="{{ $chip }}"
                                        title="Quitar filtro de situación laboral">
                                        <span class="text-secondary fw-normal">Laboral:</span>
                                        {{ request('situacion') === 'en_actividad' ? 'En actividad' : 'Dados de baja' }}
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </div>
                        @else
                            <span class="text-muted">Mostrando todo el personal</span>
                        @endif
                    </div>

                    <div class="d-flex gap-2">
                        @if ($hayFiltros)
                            <a href="{{ route('personal.index') }}"
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

        {{-- La tabla llega vacía: las filas las pide pantallas/personal.js a la API (regla 37). --}}
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Listado del personal</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Usuario</th>
                            <th scope="col" class="d-none d-md-table-cell">Rol</th>
                            <th scope="col">Acceso</th>
                            <th scope="col" class="d-none d-lg-table-cell">Situación laboral</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="5" data-vacio="Todavía no hay personal cargado."
                        data-vacio-filtrado="Nadie del personal coincide con el filtro." data-ver-todos="Ver todas">
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
                    <div class="small text-body-secondary" data-campo="email"></div>
                    {{-- En móvil la columna Rol no está: el dato no puede desaparecer --}}
                    <div class="small text-body-secondary d-md-none" data-campo="rol"></div>
                </td>

                <td class="d-none d-md-table-cell" data-campo="rol"></td>

                {{-- El estado se comunica con texto, no sólo con color --}}
                <td><span class="badge" data-campo="acceso"></span></td>

                <td class="d-none d-lg-table-cell" data-campo="situacion"></td>

                <td class="text-end text-nowrap">
                    @can('usuario.editar')
                        <a href="{{ route('personal.edit', ['usuario' => '__ID__']) }}" data-accion="editar"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Editar</span>
                        </a>
                    @endcan

                    @can('usuario.eliminar')
                        {{-- El JS lo saca en la propia cuenta; quien decide igual es el servidor (409). --}}
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/personal/__ID__">
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
