@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 gap-3 flex-wrap">
        <div>
            <h1 class="h3 mb-1">Clientes</h1>
            <p class="text-body-secondary small mb-0">
                Quien compra sin cuenta es cliente de mostrador. Las cuentas de la tienda se crean
                desde Usuarios.
            </p>
        </div>

        @can('cliente.crear')
            <a href="{{ route('clientes.create') }}" class="btn btn-acento">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo cliente
            </a>
        @endcan
    </div>

    {{-- Filtros por GET: la pantalla queda enlazable y el botón atrás funciona --}}
    <form method="GET" action="{{ route('clientes.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label for="q" class="form-label">Buscar</label>
                <input type="search" class="form-control" id="q" name="q" value="{{ request('q') }}"
                    placeholder="Nombre, documento, correo o teléfono" aria-describedby="ayudaBuscar">
            </div>

            <div class="col-12 col-md-4">
                <label for="condicion_iva" class="form-label">Condición frente al IVA</label>
                <select class="form-select" id="condicion_iva" name="condicion_iva">
                    <option value="">Todas</option>
                    @foreach (App\Models\Cliente::CONDICIONES_IVA as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('condicion_iva') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-md-3">
                <label for="cuenta" class="form-label">Cuenta de tienda</label>
                <select class="form-select" id="cuenta" name="cuenta">
                    <option value="">Todos</option>
                    <option value="con_cuenta" @selected(request('cuenta') === 'con_cuenta')>Con cuenta</option>
                    <option value="sin_cuenta" @selected(request('cuenta') === 'sin_cuenta')>De mostrador</option>
                </select>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-outline-dark">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                </button>

                @if (request()->hasAny(['q', 'condicion_iva', 'cuenta']))
                    <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                    </a>
                @endif

                <x-imprimir api="/clientes/impresion" :listado="new \App\Impresion\ListadoClientes()" :chico="false" class="ms-auto" />
            </div>
        </div>
    </form>

    {{-- La tabla llega vacía: las filas las pide pantallas/clientes.js a la API (regla 37). --}}
    <div data-pantalla="clientes">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Listado de clientes</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="d-none d-md-table-cell">Condición frente al IVA</th>
                            <th scope="col" class="d-none d-lg-table-cell">Cuenta</th>
                            <th scope="col" class="text-center d-none d-lg-table-cell">Direcciones</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="5" data-vacio="Todavía no hay clientes cargados."
                        data-vacio-filtrado="Ningún cliente coincide con el filtro." data-ver-todos="Ver todos">
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
                    <span class="fw-semibold" data-campo="razon_social"></span>

                    <div class="small text-body-secondary" data-campo="documento"></div>

                    {{-- En móvil no están las columnas de al lado: el dato no puede desaparecer --}}
                    <div class="small text-body-secondary d-md-none" data-campo="condicion-movil"></div>
                </td>

                <td class="d-none d-md-table-cell">
                    <span data-campo="condicion"></span>

                    {{-- Lo único que esta columna decide hoy --}}
                    <div class="small text-body-secondary" data-campo="comprobante"></div>
                </td>

                {{-- El estado se comunica con texto, no sólo con color --}}
                <td class="d-none d-lg-table-cell">
                    <span class="badge" data-campo="cuenta"></span>
                    <div class="small text-body-secondary" data-campo="cuenta-correo" hidden></div>
                </td>

                <td class="text-center d-none d-lg-table-cell" data-campo="direcciones"></td>

                <td class="text-end text-nowrap">
                    @can('cliente.editar')
                        <a href="{{ route('clientes.edit', ['cliente' => '__ID__']) }}" data-accion="editar"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                        </a>
                    @endcan

                    @can('cliente.eliminar')
                        <button type="button" class="btn btn-sm btn-outline-danger" data-listado-eliminar
                            data-ruta="/clientes/__ID__">
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
