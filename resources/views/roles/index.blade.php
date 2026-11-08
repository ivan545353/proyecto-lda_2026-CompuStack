@extends('layouts.app')

@section('title', 'Roles y permisos')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Roles y permisos</h1>
            <p class="text-body-secondary small mb-0">
                Lo que no está asignado, no se puede hacer. No hay permisos por omisión.
            </p>
        </div>

        @can('rol.editar')
            <a href="{{ route('roles.create') }}" class="btn btn-acento flex-shrink-0">
                <i class="bi bi-plus-lg"></i> Nuevo rol
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Listado de roles y permisos</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Rol</th>
                        <th scope="col">Ámbito</th>
                        <th scope="col" class="text-center d-none d-sm-table-cell">Permisos</th>
                        <th scope="col" class="text-center d-none d-sm-table-cell">Usuarios</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roles as $rol)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $rol->nombre }}</span>
                                @if ($rol->es_sistema)
                                    <span class="badge text-bg-secondary ms-1">Sistema</span>
                                @endif
                                <div class="text-body-secondary small">{{ $rol->descripcion }}</div>
                                <div class="small text-body-secondary d-sm-none">
                                    {{ $rol->permisos_count }} permisos · {{ $rol->usuarios_count }} usuarios
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $rol->ambito === 'gestion' ? 'text-bg-dark' : 'text-bg-info' }}">
                                    {{ ucfirst($rol->ambito) }}
                                </span>
                            </td>
                            <td class="text-center d-none d-sm-table-cell">{{ $rol->permisos_count }}</td>
                            <td class="text-center d-none d-sm-table-cell">{{ $rol->usuarios_count }}</td>
                            <td class="text-end text-nowrap">
                                @can('rol.editar')
                                    <a href="{{ route('roles.edit', $rol) }}" class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar {{ $rol->nombre }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>

                                    @unless ($rol->es_sistema || $rol->usuarios_count > 0)
                                        <form method="POST" action="{{ route('roles.destroy', $rol) }}" class="d-inline"
                                            onsubmit="return confirm('¿Eliminar el rol {{ $rol->nombre }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger"
                                                aria-label="Eliminar {{ $rol->nombre }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                No hay roles cargados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $roles->links() }}
    </div>
@endsection
