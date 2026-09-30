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
            </div>
        </div>
    </form>

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
                <tbody>
                    @forelse ($clientes as $cliente)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $cliente->razon_social }}</span>

                                <div class="small text-body-secondary">
                                    @if ($cliente->nro_doc)
                                        {{ App\Models\Cliente::TIPOS_DOC[$cliente->tipo_doc] }}
                                        {{ $cliente->nro_doc }}
                                    @else
                                        Sin documento
                                    @endif
                                </div>

                                {{-- En móvil no están las columnas de al lado: el dato
                                     no puede desaparecer --}}
                                <div class="small text-body-secondary d-md-none">
                                    {{ App\Models\Cliente::CONDICIONES_IVA[$cliente->condicion_iva] }}
                                </div>
                            </td>

                            <td class="d-none d-md-table-cell">
                                {{ App\Models\Cliente::CONDICIONES_IVA[$cliente->condicion_iva] }}

                                {{-- Lo único que esta columna decide hoy --}}
                                <div class="small text-body-secondary">
                                    {{ $cliente->tipoComprobante() === 'factura_a' ? 'Factura A' : 'Factura B' }}
                                </div>
                            </td>

                            {{-- El estado se comunica con texto, no sólo con color --}}
                            <td class="d-none d-lg-table-cell">
                                @if ($cliente->esDeMostrador())
                                    <span class="badge text-bg-secondary">De mostrador</span>
                                @else
                                    <span class="badge text-bg-dark">Con cuenta</span>
                                    <div class="small text-body-secondary">{{ $cliente->usuario->email }}</div>
                                @endif
                            </td>

                            <td class="text-center d-none d-lg-table-cell">{{ $cliente->direcciones_count }}</td>

                            <td class="text-end text-nowrap">
                                @can('cliente.editar')
                                    <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-sm btn-outline-dark">
                                        <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                    </a>
                                @endcan

                                @can('cliente.eliminar')
                                    <form method="POST" action="{{ route('clientes.destroy', $cliente) }}" class="d-inline"
                                        onsubmit="return confirm(@js("¿Eliminar a «{$cliente->razon_social}» y sus {$cliente->direcciones_count} dirección(es)? Si tiene ventas registradas o cuenta de acceso, no se va a poder eliminar."));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Eliminar el cliente {{ $cliente->razon_social }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        {{-- El vacío por filtro y el vacío por falta de datos dicen
                             cosas distintas --}}
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">
                                @if (request()->hasAny(['q', 'condicion_iva', 'cuenta']))
                                    Ningún cliente coincide con el filtro.
                                    <a href="{{ route('clientes.index') }}">Ver todos</a>.
                                @else
                                    Todavía no hay clientes cargados.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($clientes->total() > 0)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $clientes->firstItem() }}–{{ $clientes->lastItem() }} de {{ $clientes->total() }}.
            </p>

            {{ $clientes->links() }}
        </div>
    @endif
@endsection
