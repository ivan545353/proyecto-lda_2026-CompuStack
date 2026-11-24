@extends('layouts.app')

@section('title', 'Roles y permisos')

{{-- Shell (regla 37): las filas las pide pantallas/roles.js a la API. --}}
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

    <div data-pantalla="roles">
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
                    <tbody data-listado-filas data-columnas="5" data-vacio="No hay roles cargados.">
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
                    <span class="badge text-bg-secondary ms-1" data-campo="sistema" hidden>Sistema</span>
                    <div class="text-body-secondary small" data-campo="descripcion"></div>
                    <div class="small text-body-secondary d-sm-none" data-campo="conteos-movil"></div>
                </td>
                {{-- El ámbito se comunica con texto, no sólo con color --}}
                <td><span class="badge" data-campo="ambito"></span></td>
                <td class="text-center d-none d-sm-table-cell" data-campo="permisos"></td>
                <td class="text-center d-none d-sm-table-cell" data-campo="usuarios"></td>
                <td class="text-end text-nowrap">
                    @can('rol.editar')
                        <a href="{{ route('roles.edit', ['rol' => '__ID__']) }}" data-accion="editar"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Editar</span>
                        </a>

                        {{-- El JS lo saca si el rol es de sistema o tiene usuarios; el servicio lo rechaza igual (409). --}}
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/roles/__ID__">
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
