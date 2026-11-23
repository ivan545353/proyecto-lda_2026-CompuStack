@extends('layouts.app')

@section('title', 'Armar pedido')

{{-- Shell (tramo G, regla 37): qué reponer llega por `GET /api/v1/compras/sugeridos`, con los grupos por
     proveedor y lo que no se puede pedir. El costo, sólo con precio.ver_costo: sin permiso la clave no
     viaja, y la columna tampoco está. Elegir sigue siendo un GET al armador con `productos[]`. --}}
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

    <div data-pantalla="sugeridos" data-armador-url="{{ route('compras.pedido') }}">
        <div class="row g-2 mb-3" data-totales hidden>
            <div class="col-6 col-md-auto">
                <div class="p-2 px-3 border rounded-3 bg-light text-center h-100">
                    <div class="text-body-secondary small">Productos para pedir</div>
                    <div class="fs-4 fw-bold text-dark" data-campo="total"></div>
                </div>
            </div>

            @can('precio.ver_costo')
                <div class="col-6 col-md-auto">
                    <div class="p-2 px-3 border rounded-3 bg-light text-center h-100">
                        <div class="text-body-secondary small">Estimado de esta página</div>
                        <div class="fs-4 fw-bold text-dark" data-campo="estimado_pagina"></div>
                    </div>
                </div>
            @endcan
        </div>

        {{-- La única advertencia de la pantalla, y es operativa: a estos productos no se
             los va a pedir nadie, ni acá ni la reposición automática. --}}
        <div class="alert alert-warning d-flex gap-2" role="alert" id="avisoSinProveedor" data-sin-proveedor hidden>
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong data-campo="sin_proveedor_texto"></strong>
                Están por debajo del mínimo y no hay a quién comprarles, así que no aparecen en esta
                lista y la reposición automática tampoco los va a pedir.
                @can('producto.ver')
                    <a href="{{ route('productos.index', ['stock' => 'sin_proveedor']) }}">Ver cuáles son</a>
                    y cargales un proveedor.
                @endcan
            </div>
        </div>

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

        <form method="GET" action="{{ route('compras.pedido') }}" id="formSugeridos" data-seleccion data-seleccion-diferida>
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
                                    <input class="form-check-input casilla-fila mt-0" type="checkbox"
                                        data-seleccion-todas="formSugeridos"
                                        aria-label="Marcar todos los productos de esta página">
                                </th>
                                <th scope="col">Producto</th>
                                <th scope="col" class="text-end">Disponible</th>
                                <th scope="col" class="text-end">Conviene pedir</th>
                                @can('precio.ver_costo')
                                    <th scope="col" class="text-end d-none d-md-table-cell">Último costo</th>
                                @endcan
                            </tr>
                        </thead>

                        {{-- Las filas llegan en el orden de la API; el JS las pone debajo de la cabecera
                             de su proveedor, con los grupos en el orden que trae `grupos`. --}}
                        <tbody data-listado-filas data-columnas="{{ auth()->user()->can('precio.ver_costo') ? 5 : 4 }}"
                            data-vacio="Nada para pedir ahora: no quedó ningún producto por debajo del mínimo sin un pedido en curso."
                            data-vacio-filtrado="Nada para pedir ahora: no quedó ningún producto por debajo del mínimo sin un pedido en curso.">
                            <tr>
                                <td colspan="5" class="text-center text-body-secondary py-4">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <template data-listado-fila>
                    <tr>
                        <td>
                            <input class="form-check-input casilla-fila mt-0" type="checkbox" name="productos[]"
                                value="__ID__" data-casilla>
                        </td>

                        <td>
                            <span class="fw-semibold" data-campo="nombre"></span>
                            <div class="small text-body-secondary" data-campo="codigo"></div>
                        </td>

                        <td class="text-end">
                            {{-- Todas las filas están en stock bajo: un badge en todas no
                                 diría nada. El rojo queda para el agotado, que sí distingue. --}}
                            <span class="badge text-bg-danger" data-si="agotado">Sin stock</span>
                            <span data-si="con_stock" data-campo="disponible"></span>
                            <div class="small text-body-secondary">Mínimo <span data-campo="stock_minimo"></span></div>
                        </td>

                        <td class="text-end">
                            <span class="badge text-bg-dark" data-campo="cantidad_reposicion"></span>
                            <div class="small text-body-secondary" data-si="sin_cantidad">Escribí la cantidad al revisar</div>
                        </td>

                        @can('precio.ver_costo')
                            <td class="text-end d-none d-md-table-cell">
                                <span class="text-body-secondary" data-si="sin_costo">Sin costo cargado</span>
                                <span data-si="con_costo" data-campo="costo_ultimo"></span>
                            </td>
                        @endcan
                    </tr>
                </template>

                <template data-plantilla-grupo>
                    <tr class="table-light" data-cabecera-grupo>
                        <th colspan="{{ auth()->user()->can('precio.ver_costo') ? 5 : 4 }}" class="fw-normal py-2">
                            <div
                                class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2">
                                <div>
                                    <span class="fw-semibold" data-campo="proveedor"></span>
                                    <span class="text-body-secondary small ms-1" data-campo="resumen"></span>
                                </div>

                                <a href="#" class="btn btn-sm btn-acento text-nowrap" data-pedir-grupo>
                                    <span data-campo="pedir"></span>
                                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </a>
                            </div>
                        </th>
                    </tr>
                </template>

                <div
                    class="card-footer bg-white border-top d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 py-3"
                    data-pedir-marcados hidden>
                    <p class="text-body-secondary small mb-0" data-seleccion-contador
                        data-seleccion-marcadas="{n} producto(s) marcados en esta página.">
                        O marcá los que quieras de distintos proveedores. Lo que marques es de esta página.
                    </p>

                    <button type="submit" class="btn btn-outline-dark text-nowrap">
                        Pedir los marcados
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </form>

        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3" data-listado-pie hidden>
            <p class="text-body-secondary small mb-0" data-listado-resumen></p>
            <div class="d-flex justify-content-center" data-listado-paginas></div>
        </div>
    </div>
@endsection
