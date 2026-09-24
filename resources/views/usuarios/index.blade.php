@extends('layouts.app')

@section('title', 'Usuarios')

@php
    $hayFiltros = request()->hasAny(['q', 'rol_id', 'ambito', 'estado', 'situacion']);
@endphp

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Usuarios</h1>
            <p class="text-body-secondary small mb-0">
                Cuentas del sistema.
            </p>
        </div>

        @can('usuario.crear')
            <a href="{{ route('usuarios.create') }}" class="btn btn-acento">
                <i class="bi bi-person-plus" aria-hidden="true"></i> Nueva persona
            </a>
        @endcan
    </div>

    {{-- Filtros por GET: la pantalla queda enlazable y el botón atrás funciona --}}
    <form method="GET" action="{{ route('usuarios.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="q" class="form-label">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-muted">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="Nombre, apellido, correo o legajo"
                        aria-describedby="ayudaBuscar">
                </div>

            </div>

            <div class="col-12 col-md-4 col-lg-2">
                <label for="rol_id" class="form-label">Rol</label>
                <select class="form-select" id="rol_id" name="rol_id" data-buscable>
                    <option value="">Todos</option>
                    @foreach ($roles as $rol)
                        <option value="{{ $rol->id }}" @selected((int) request('rol_id') === $rol->id)>{{ $rol->nombre }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <label for="ambito" class="form-label">Tipo</label>
                <select class="form-select" id="ambito" name="ambito">
                    <option value="">Todos</option>
                    <option value="gestion" @selected(request('ambito') === 'gestion')>Personal</option>
                    <option value="tienda" @selected(request('ambito') === 'tienda')>Cuentas de clientes</option>
                </select>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <label for="estado" class="form-label">Acceso</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    <option value="activos" @selected(request('estado') === 'activos')>Con acceso</option>
                    <option value="inactivos" @selected(request('estado') === 'inactivos')>Sin acceso</option>
                </select>
            </div>

            <div class="col-6 col-md-4 col-lg-2">
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
                            <a href="{{ route('usuarios.index') }}"
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

                            @if (request()->filled('rol_id'))
                                <a href="{{ request()->fullUrlWithoutQuery(['rol_id', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de rol">
                                    <span class="text-secondary fw-normal">Rol:</span>
                                    {{ $roles->firstWhere('id', (int) request('rol_id'))?->nombre ?? request('rol_id') }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('ambito'))
                                <a href="{{ request()->fullUrlWithoutQuery(['ambito', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de ámbito">
                                    <span class="text-secondary fw-normal">Ámbito:</span>
                                    {{ request('ambito') === 'gestion' ? 'Personal' : 'Clientes' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('estado'))
                                <a href="{{ request()->fullUrlWithoutQuery(['estado', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de acceso">
                                    <span class="text-secondary fw-normal">Acceso:</span>
                                    {{ request('estado') === 'activos' ? 'Con acceso' : 'Sin acceso' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (request()->filled('situacion'))
                                <a href="{{ request()->fullUrlWithoutQuery(['situacion', 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar filtro de situación laboral">
                                    <span class="text-secondary fw-normal">Laboral:</span>
                                    {{ request('situacion') === 'en_actividad' ? 'En actividad' : 'Dados de baja' }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    @else
                        <span class="text-muted">Mostrando todas las personas</span>
                    @endif
                </div>

                <div class="d-flex gap-2">
                    @if ($hayFiltros)
                        <a href="{{ route('usuarios.index') }}"
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
                <caption class="visually-hidden">Listado de cuentas del sistema</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Persona</th>
                        <th scope="col" class="d-none d-md-table-cell">Rol</th>
                        <th scope="col">Acceso</th>
                        <th scope="col" class="d-none d-lg-table-cell">Situación laboral</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($usuarios as $usuario)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $usuario->nombre_completo }}</span>
                                <div class="small text-body-secondary">{{ $usuario->email }}</div>

                                {{-- En móvil la columna Rol no está: el dato no puede desaparecer --}}
                                <div class="small text-body-secondary d-md-none">{{ $usuario->rol->nombre }}</div>
                            </td>

                            <td class="d-none d-md-table-cell">
                                {{ $usuario->rol->nombre }}
                                @if (!$usuario->rol->esDeGestion())
                                    <div class="small text-body-secondary">Cuenta de cliente</div>
                                @endif
                            </td>

                            {{-- El estado se comunica con texto, no sólo con color --}}
                            <td>
                                <span class="badge {{ $usuario->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $usuario->activo ? 'Con acceso' : 'Sin acceso' }}
                                </span>
                            </td>

                            <td class="d-none d-lg-table-cell">
                                @if (!$usuario->empleado)
                                    <span class="text-body-secondary">No corresponde</span>
                                @elseif ($usuario->empleado->fecha_baja)
                                    <span class="text-body-secondary">
                                        Baja el {{ $usuario->empleado->fecha_baja->format('d/m/Y') }}
                                    </span>
                                @else
                                    En actividad
                                @endif
                            </td>

                            <td class="text-end text-nowrap">
                                @can('usuario.editar')
                                    <a href="{{ route('usuarios.edit', $usuario) }}" class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar {{ $usuario->nombre_completo }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan

                                @can('usuario.eliminar')
                                    @unless ($usuario->is(auth()->user()))
                                        <form method="POST" action="{{ route('usuarios.destroy', $usuario) }}" class="d-inline"
                                            onsubmit="return confirm(@js("¿Eliminar la cuenta de {$usuario->nombre_completo}? Si tiene operaciones registradas no se va a eliminar: se le va a quitar el acceso."));">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger"
                                                aria-label="Eliminar la cuenta de {{ $usuario->nombre_completo }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        {{-- El vacío por filtro y el vacío por falta de datos dicen cosas distintas --}}
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                @if (request()->hasAny(['q', 'rol_id', 'ambito', 'estado', 'situacion']))
                                    Ninguna persona coincide con el filtro.
                                    <a href="{{ route('usuarios.index') }}">Ver todas</a>.
                                @else
                                    Todavía no hay personas cargadas.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($usuarios->total() > 0)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $usuarios->firstItem() }}–{{ $usuarios->lastItem() }} de {{ $usuarios->total() }}.
            </p>

            {{ $usuarios->links() }}
        </div>
    @endif
@endsection
