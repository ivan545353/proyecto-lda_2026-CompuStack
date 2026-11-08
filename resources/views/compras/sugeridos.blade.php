@extends('layouts.app')

@use('App\Support\Importe')

@section('title', 'Armar pedido')

@php($verCosto = auth()->user()->can('precio.ver_costo'))
@php($columnas = $verCosto ? 5 : 4)

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-3">
        <h1 class="h3 mb-1">Armar pedido</h1>

        {{-- Los dos pasos, numerados: en esta pantalla se elige, en el armador se
             revisa y se guarda. Nada se guarda desde acá. --}}
        <ol class="list-inline small text-body-secondary mb-0">
            <li class="list-inline-item">
                <span class="badge rounded-pill text-bg-dark">1</span>
                Elegí qué pedir
            </li>
            <li class="list-inline-item" aria-hidden="true">
                <i class="bi bi-chevron-right"></i>
            </li>
            <li class="list-inline-item">
                <span class="badge rounded-pill text-bg-light border text-body-secondary">2</span>
                Revisá cantidades y costos en el armador y guardá: se crea un pedido en borrador por proveedor
            </li>
        </ol>
    </div>

    @include('compras._pestanas-pedido', ['activa' => 'sugeridos'])

    @if ($sugeridos->total() > 0)
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-auto">
                <div class="p-2 px-3 border rounded-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Productos para pedir</div>
                    <div class="fs-4 fw-bold text-dark">{{ $sugeridos->total() }}</div>
                </div>
            </div>

            @if ($verCosto)
                <div class="col-6 col-md-auto">
                    <div class="p-2 px-3 border rounded-3 bg-light text-center h-100">
                        <div class="text-body-secondary small">Estimado de esta página</div>
                        <div class="fs-4 fw-bold text-dark">{{ Importe::pesos($estimadoPagina) }}</div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- La única advertencia de la pantalla, y es operativa: a estos productos no se
         los va a pedir nadie, ni acá ni la reposición automática. --}}
    @if ($sinProveedor > 0)
        <div class="alert alert-warning d-flex gap-2" role="alert" id="avisoSinProveedor"
            data-sin-proveedor="{{ $sinProveedor }}">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>
                    @if ($sinProveedor === 1)
                        1 producto no se puede pedir porque no tiene proveedor.
                    @else
                        {{ $sinProveedor }} productos no se pueden pedir porque no tienen proveedor.
                    @endif
                </strong>
                Están por debajo del mínimo y no hay a quién comprarles, así que no aparecen en esta
                lista y la reposición automática tampoco los va a pedir.
                @can('producto.ver')
                    <a href="{{ route('productos.index', ['stock' => 'sin_proveedor']) }}">Ver cuáles son</a>
                    y cargales un proveedor.
                @endcan
            </div>
        </div>
    @endif

    {{-- La explicación conceptual va plegada: quien usa la pantalla todos los días no
         la necesita abierta, y quien entra por primera vez la encuentra. --}}
    <p class="mb-3">
        <a class="btn btn-sm btn-link link-dark px-0" data-bs-toggle="collapse" href="#comoSeDecide" role="button"
            aria-expanded="false" aria-controls="comoSeDecide">
            ¿Cómo decide el sistema qué hay que reponer?
        </a>
    </p>

    <div class="collapse mb-3" id="comoSeDecide">
        <div class="card card-body border-0 bg-body-tertiary small">
            Un producto entra en esta lista cuando lo disponible —el stock menos lo reservado por
            ventas sin cobrar— está en el mínimo o por debajo, tiene un mínimo declarado y no tiene
            ningún pedido en curso. Es la misma regla con la que el sistema genera los borradores
            automáticos todas las mañanas, así que las dos listas nunca dicen cosas distintas del
            mismo producto.
        </div>
    </div>

    <form method="GET" action="{{ route('compras.pedido') }}" id="formSugeridos" data-seleccion>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Qué hay que reponer</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Agrupados por el proveedor al que conviene pedirle cada uno: el preferido, y si no hay ninguno marcado, el más barato de los activos."
                    aria-label="Información sobre el agrupamiento por proveedor">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">
                        Productos por debajo del mínimo, agrupados por el proveedor al que se le va a pedir cada uno
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col" style="width: 40px;">
                                {{-- Marcar todos los de la página es comodidad y necesita JS. Las
                                     casillas de cada fila funcionan sin él. --}}
                                <input class="form-check-input casilla-fila mt-0" type="checkbox"
                                    data-seleccion-todas="formSugeridos"
                                    aria-label="Marcar todos los productos de esta página">
                            </th>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Disponible</th>
                            <th scope="col" class="text-end">Conviene pedir</th>
                            @if ($verCosto)
                                <th scope="col" class="text-end d-none d-md-table-cell">Último costo</th>
                            @endif
                        </tr>
                    </thead>

                    @forelse ($grupos as $grupo)
                        <tbody>
                            <tr class="table-light">
                                <th colspan="{{ $columnas }}" class="fw-normal py-2">
                                    <div
                                        class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                                        <div>
                                            <span class="fw-semibold">{{ $grupo['proveedor'] }}</span>
                                            <span class="text-body-secondary small ms-1">
                                                {{ $grupo['productos']->count() }}
                                                {{ $grupo['productos']->count() === 1 ? 'producto' : 'productos' }}
                                                @if ($verCosto)
                                                    · estimado {{ Importe::pesos($grupo['estimado']) }}
                                                @endif
                                            </span>
                                        </div>

                                        <a href="{{ route('compras.pedido', ['productos' => $grupo['productos']->pluck('id')->all()]) }}"
                                            class="btn btn-sm btn-acento text-nowrap"
                                            aria-label="Armar el pedido con los {{ $grupo['productos']->count() }} productos de {{ $grupo['proveedor'] }}">
                                            Pedirle estos {{ $grupo['productos']->count() }}
                                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </th>
                            </tr>

                            @foreach ($grupo['productos'] as $producto)
                                @php($proveedor = $producto->proveedorParaReponer())

                                <tr>
                                    <td>
                                        <input class="form-check-input casilla-fila mt-0" type="checkbox" name="productos[]"
                                            value="{{ $producto->id }}"
                                            aria-label="Marcar {{ $producto->rotulo() }} para el pedido">
                                    </td>

                                    <td>
                                        <span class="fw-semibold">{{ $producto->nombre }}</span>
                                        <div class="small text-body-secondary">{{ $producto->codigo }}</div>
                                    </td>

                                    <td class="text-end">
                                        {{-- Todas las filas están en stock bajo: un badge en todas no
                                             diría nada. El rojo queda para el agotado, que sí distingue. --}}
                                        @if ($producto->stock_disponible <= 0)
                                            <span class="badge text-bg-danger">Sin stock</span>
                                        @else
                                            {{ $producto->stock_disponible }}
                                        @endif
                                        <div class="small text-body-secondary">
                                            Mínimo {{ $producto->stock_minimo }}
                                        </div>
                                    </td>

                                    <td class="text-end">
                                        <span class="badge text-bg-dark">{{ $producto->cantidad_reposicion }}</span>
                                        @if ($producto->cantidad_reposicion < 1)
                                            <div class="small text-body-secondary">
                                                Escribí la cantidad al revisar
                                            </div>
                                        @endif
                                    </td>

                                    @if ($verCosto)
                                        <td class="text-end d-none d-md-table-cell">
                                            @if ($proveedor->pivot->costo_ultimo === null)
                                                <span class="text-body-secondary">Sin costo cargado</span>
                                            @else
                                                {{ Importe::pesos($proveedor->pivot->costo_ultimo) }}
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody>
                            <tr>
                                <td colspan="{{ $columnas }}" class="text-center text-body-secondary py-4">
                                    Nada para pedir ahora: no quedó ningún producto por debajo del mínimo
                                    sin un pedido en curso.
                                </td>
                            </tr>
                        </tbody>
                    @endforelse
                </table>
            </div>

            @if ($sugeridos->total() > 0)
                <div
                    class="card-footer bg-white border-top d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 py-3">
                    <p class="text-body-secondary small mb-0" data-seleccion-contador
                        data-seleccion-marcadas="{n} producto(s) marcados en esta página.">
                        O marcá los que quieras de distintos proveedores. Lo que marques es de esta página.
                    </p>

                    <button type="submit" class="btn btn-outline-dark text-nowrap">
                        Pedir los marcados
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            @endif
        </div>
    </form>

    @if ($sugeridos->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $sugeridos->firstItem() }}–{{ $sugeridos->lastItem() }} de {{ $sugeridos->total() }}.
            </p>
            {{ $sugeridos->links() }}
        </div>
    @endif
@endsection
