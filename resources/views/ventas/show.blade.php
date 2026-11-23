@extends('layouts.app')

@section('title', 'Venta')

{{-- Shell (tramo H, regla 37): la venta llega por `GET /api/v1/ventas/{venta}`. Cada acción tiene su
     `@can` (regla 12) y nace oculta; la muestra el JS si `acciones` dice que el estado la admite. Ninguna
     de las dos cosas es autoridad: la API vuelve a decidir en cada petición. --}}
@section('content')
    <div data-pantalla="venta" data-venta="{{ $venta->id }}" aria-busy="true">
    {{-- Arriba y no sólo abajo: en un documento largo, salir no debería exigir bajar hasta el final. --}}
    <a href="{{ route('ventas.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <p class="text-body-secondary" data-cargando>Cargando la venta…</p>

    <div data-ficha hidden>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                Venta <span data-campo="numero"></span>
                {{-- El estado nunca se comunica sólo con color: el badge lleva texto. --}}
                <span class="badge align-middle ms-2" data-campo="estado_texto"></span>
                <span class="badge text-bg-warning align-middle ms-1" id="insigniaSinStock" data-si="sin_stock" hidden>
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                    Sin stock suficiente
                </span>
            </h1>

            <p class="text-body-secondary small mb-0">
                A <span class="fw-semibold" data-campo="cliente_texto"></span>, por
                <span data-campo="total"></span>.
                <span data-si="presupuesto" hidden>
                    Un presupuesto no descuenta stock ni genera comprobante: se convierte en venta
                    cuando se cobra.
                </span>
                <span data-si="cancelada" hidden>
                    Se canceló antes de cobrarse, así que no movió stock ni generó comprobante.
                </span>
            </p>
        </div>

        {{-- La acción principal, a la vista sin bajar hasta el final; se repite en la tarjeta de acciones. --}}
        <div class="d-flex flex-column flex-sm-row gap-2">
            @can('venta.editar')
                <a href="{{ route('ventas.edit', $venta->id) }}" class="btn btn-outline-dark text-nowrap" data-accion="editar" hidden>
                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar el presupuesto
                </a>
            @endcan

            {{-- Descargar es una lectura y no una transición: va acá y no en la tarjeta de acciones.
                 El PDF va por enlace firmado (D-22). --}}
            @can('venta.ver')
                <button type="button" class="btn btn-outline-dark text-nowrap" data-accion="imprimir" hidden>
                    <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                    Descargar <span data-campo="documento"></span>
                </button>
            @endcan
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Cliente</h2>

                <div data-si="cliente" hidden>
                    <p class="mb-1 fw-semibold" data-campo="razon_social"></p>

                    <dl class="row small mb-2">
                        <dt class="col-5 fw-normal text-body-secondary" data-si="nro_doc" data-campo="tipo_doc_texto"></dt>
                        <dd class="col-7 mb-1" data-si="nro_doc" data-campo="nro_doc"></dd>

                        <dt class="col-5 fw-normal text-body-secondary">Frente al IVA</dt>
                        <dd class="col-7 mb-1" data-campo="condicion_iva_texto"></dd>

                        <dt class="col-5 fw-normal text-body-secondary" data-si="telefono">Teléfono</dt>
                        <dd class="col-7 mb-1" data-si="telefono" data-campo="telefono"></dd>

                        <dt class="col-5 fw-normal text-body-secondary" data-si="email">Correo</dt>
                        <dd class="col-7 mb-0" data-si="email" data-campo="email"></dd>
                    </dl>

                    <div class="d-flex flex-wrap gap-2 mt-auto">
                        {{-- Desde una venta se llega al historial de compras de ese cliente (pendiente #8). --}}
                        <a href="{{ route('ventas.index') }}?cliente_id=__CLIENTE__" class="btn btn-sm btn-outline-dark" data-enlace-cliente>
                            <i class="bi bi-clock-history" aria-hidden="true"></i> Sus compras
                        </a>

                        @can('cliente.editar')
                            <a href="{{ route('clientes.edit', '__CLIENTE__') }}" class="btn btn-sm btn-outline-secondary" data-enlace-cliente>
                                <i class="bi bi-person-lines-fill" aria-hidden="true"></i> Su ficha
                            </a>
                        @endcan
                    </div>
                </div>

                <div data-si="sin_cliente" hidden>
                    <p class="mb-0 fw-semibold" data-campo="cliente_texto"></p>
                    {{-- cliente_id en null SIGNIFICA esto: la venta de mostrador no identifica al comprador. --}}
                    <p class="small text-body-secondary mb-0">
                        La venta no identifica al comprador, que es lo habitual en el mostrador.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Quién y cuándo</h2>

                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal text-body-secondary">Emitida</dt>
                    <dd class="col-7 mb-1" data-campo="emitida"></dd>

                    <dt class="col-5 fw-normal text-body-secondary" data-si="modificada">Última modificación</dt>
                    <dd class="col-7 mb-1" data-si="modificada" data-campo="modificada"></dd>

                    <dt class="col-5 fw-normal text-body-secondary">Entrega</dt>
                    <dd class="col-7 mb-1">Retiro en el local</dd>

                    <dt class="col-5 fw-normal text-body-secondary">Productos</dt>
                    <dd class="col-7 mb-0" data-campo="productos"></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Productos de la venta</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Cantidad</th>
                        <th scope="col" class="text-end" data-si="hay_entregas">Entregadas</th>
                        <th scope="col" class="text-end" data-si="hay_devoluciones">Devueltas</th>
                        <th scope="col" class="text-end">Precio unitario</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Neto</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">IVA</th>
                        <th scope="col" class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody data-lineas></tbody>
                <tfoot>
                    <tr>
                        <td data-antes-del-total class="text-end text-body-secondary">Subtotal</td>
                        <td class="text-end text-nowrap" data-campo="subtotal"></td>
                    </tr>
                    <tr data-si="descuento">
                        <td data-antes-del-total class="text-end text-body-secondary">
                            Descuento (<span data-campo="descuento_porcentaje"></span> %)
                        </td>
                        <td class="text-end text-nowrap">− <span data-campo="descuento"></span></td>
                    </tr>
                    <tr>
                        <td data-antes-del-total class="text-end fw-semibold">Total</td>
                        <td class="text-end fw-semibold text-nowrap" data-campo="total"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <template data-plantilla-linea>
        <tr>
            <td>
                {{-- La descripción se copió al cotizar: sigue diciendo qué se vendió aunque el producto cambie. --}}
                <span class="fw-semibold" data-campo="descripcion"></span>
                <div class="small text-body-secondary" data-campo="detalle"></div>
            </td>
            <td class="text-end">
                <span data-campo="cantidad"></span>
                <div class="small text-warning-emphasis text-nowrap" data-si="faltante" data-campo="faltante"></div>
            </td>
            <td class="text-end text-nowrap" data-si="hay_entregas">
                <span data-campo="cantidad_entregada"></span>
                <div class="small text-body-secondary" data-si="falta_entregar" data-campo="falta_entregar"></div>
            </td>
            <td class="text-end" data-si="hay_devoluciones">
                <span class="badge text-bg-warning" data-si="devuelta" data-campo="cantidad_devuelta"></span>
                <span class="text-body-secondary" data-si="sin_devolver">—</span>
            </td>
            <td class="text-end text-nowrap" data-campo="precio_unitario"></td>
            <td class="text-end text-nowrap d-none d-md-table-cell" data-campo="neto"></td>
            <td class="text-end d-none d-md-table-cell" data-impuestos></td>
            <td class="text-end text-nowrap" data-campo="total"></td>
        </tr>
    </template>

    <p class="text-body-secondary small">
        <i class="bi bi-lock" aria-hidden="true"></i>
        Los precios de este documento quedaron congelados cuando se cotizó: si el producto
        cambia de precio, la venta no cambia.
    </p>

    {{-- La transferencia de la tienda (D-27): lo que cargó el comprador, para revisarlo antes de cobrar. --}}
    <div class="card card-body border-0 shadow-sm mb-4" data-transferencia data-si="transferencia" hidden>
        <h2 class="h5 mb-1">Pago por transferencia</h2>

        <p class="text-body-secondary small mb-0" data-si="sin_comprobantes">
            El comprador todavía no cargó el comprobante. Hasta que llegue, el pedido no se cobra.
        </p>

        <div data-si="con_comprobantes">
            <p class="text-body-secondary small mb-2">
                Revisá que la plata haya entrado a la cuenta antes de cobrar: el comprobante lo cargó el
                comprador, no lo confirma el banco.
            </p>
            <ul class="list-unstyled mb-0" data-comprobantes></ul>
        </div>
    </div>

    <template data-plantilla-comprobante>
        <li class="py-1">
            @can('venta.ver_comprobante_transferencia')
                <button type="button" class="btn btn-link p-0 align-baseline" data-abrir-comprobante>
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span data-campo="nombre_original"></span>
                </button>
            @else
                <span data-campo="nombre_original"></span>
            @endcan
            <span class="small text-body-secondary">· cargado el <span data-campo="fecha"></span></span>
        </li>
    </template>

    <div class="card card-body border-0 shadow-sm mb-4" data-si="observaciones" hidden>
        <h2 class="h6 text-body-secondary">Observaciones</h2>
        <p class="mb-0" data-campo="observaciones"></p>
    </div>

    <div class="card border-0 shadow-sm mb-4" data-si="hay_entregas" hidden>
        <div class="card-body pb-3">
            <h2 class="h5 mb-0">Entregas</h2>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Entregas de la venta</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Comprobante</th>
                        <th scope="col">Fecha</th>
                        <th scope="col" class="d-none d-md-table-cell">Entregó</th>
                        <th scope="col" class="text-end">Unidades</th>
                        <th scope="col" class="text-end"><span class="visually-hidden">Descargar</span></th>
                    </tr>
                </thead>
                <tbody data-entregas></tbody>
            </table>
        </div>
    </div>

    <template data-plantilla-entrega>
        <tr>
            <td class="fw-semibold" data-campo="numero"></td>
            <td class="text-nowrap" data-campo="fecha"></td>
            <td class="d-none d-md-table-cell" data-campo="quien"></td>
            <td class="text-end" data-campo="unidades"></td>
            <td class="text-end">
                @can('venta.ver')
                    <button type="button" class="btn btn-sm btn-outline-dark" data-descargar-entrega>
                        <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">Descargar</span>
                    </button>
                @endcan
            </td>
        </tr>
    </template>

    <p class="text-body-secondary small" id="entregaSinComprobante" data-si="entregada_sin_comprobante" hidden>
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Esta venta se entregó antes de que el sistema registrara cada entrega, así que no tiene
        comprobante de entrega.
    </p>

    @canany(['venta.editar', 'venta.cobrar', 'venta.entregar', 'venta.anular'])
        <div class="card card-body border-0 shadow-sm mb-4">
            <h2 class="h5 mb-3">Acciones</h2>

            {{-- Operar el presupuesto. Editar y recotizar no son transiciones de estado: operan un documento
                 que todavía no tuvo ningún efecto. --}}
            @can('venta.editar')
                <div data-si="presupuesto" hidden>
                    <div class="d-grid d-sm-flex flex-wrap gap-2 mb-3">
                        <a href="{{ route('ventas.edit', $venta->id) }}" class="btn btn-outline-dark" data-accion="editar" hidden>
                            <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                        </a>

                        <button type="button" class="btn btn-outline-dark" data-accion="recotizar" hidden>
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recotizar
                        </button>
                    </div>

                    <p class="text-body-secondary small mb-3">
                        Podés editar manteniendo precios anteriores o recotizar para actualizar todas las líneas con los valores de
                        hoy.
                    </p>
                </div>
            @endcan

            {{-- Un estado final es una lista vacía en la tabla de transiciones. --}}
            <p class="text-body-secondary mb-0" data-sin-salidas hidden>
                Esta venta está «<span data-campo="estado_texto"></span>» y no admite más cambios de estado.
            </p>

            <div data-salidas hidden>
                <div class="d-grid d-sm-flex flex-wrap gap-2">
                    @can('venta.cobrar')
                        <a href="{{ route('pagos.create', $venta->id) }}" class="btn btn-acento" data-accion="cobrar" hidden>
                            <i class="bi bi-cash-coin" aria-hidden="true"></i> Cobrar
                        </a>
                    @endcan

                    @can('venta.entregar')
                        <button type="button" class="btn btn-outline-dark" data-accion="entregar" hidden>
                            <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
                            <span data-campo="entregar_texto"></span>
                        </button>

                        {{-- Con una sola unidad pendiente no hay parte que elegir: lo dice `entregar_parte`. --}}
                        <a href="{{ route('ventas.entrega', $venta->id) }}" class="btn btn-outline-dark" data-accion="entregar_parte" hidden>
                            <i class="bi bi-boxes" aria-hidden="true"></i> Entregar una parte
                        </a>
                    @endcan

                    @can('venta.anular')
                        {{-- Una venta cobrada no se cancela: se devuelve, y la devolución repone el stock y
                             revierte los pagos (A-11). --}}
                        <a href="{{ route('ventas.devolucion', $venta->id) }}" class="btn btn-outline-danger" data-accion="devolver" hidden>
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Devolver
                        </a>
                    @endcan

                    @can('venta.editar')
                        {{-- Cancelar exige `venta.editar` y no `venta.anular`: no mueve stock ni plata. --}}
                        <button type="button" class="btn btn-outline-danger" data-accion="cancelar" hidden>
                            <i class="bi bi-x-circle" aria-hidden="true"></i>
                            <span data-campo="cancelar_texto"></span>
                        </button>
                    @endcan
                </div>

                <p class="text-body-secondary small mb-0 mt-3" data-si="presupuesto" hidden>
                    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                    Cobrar convierte el presupuesto en venta y descuenta el stock en el kardex.
                </p>
            </div>
        </div>
    @endcanany

    {{-- El libro de la venta: una devolución no borra ni edita el cobro, le suma un asiento en contra (A-11). --}}
    <div class="card border-0 shadow-sm" data-si="hay_pagos" hidden>
        <div class="card-body pb-3">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Movimientos de dinero</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Registro inmutable de pagos y reintegros asociados a la venta."
                    aria-label="Información sobre movimientos de dinero">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Pagos y devoluciones de la venta</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Medio</th>
                        <th scope="col" class="d-none d-md-table-cell">Registró</th>
                        <th scope="col" class="text-end">Monto</th>
                    </tr>
                </thead>
                <tbody data-pagos></tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end fw-semibold">Quedó cobrado</td>
                        <td class="text-end fw-semibold text-nowrap" data-campo="pagado"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <template data-plantilla-pago>
        <tr>
            {{-- La fecha del hecho, no la de la fila: un webhook con atraso no coincide. --}}
            <td class="text-nowrap" data-campo="fecha"></td>
            <td>
                <span data-campo="metodo_texto"></span>
                {{-- El estado nunca se comunica sólo con color. --}}
                <span class="badge text-bg-warning ms-1" data-si="es_devolucion">Devolución</span>
            </td>
            <td class="d-none d-md-table-cell" data-campo="registro"></td>
            <td class="text-end text-nowrap" data-campo="monto"></td>
        </tr>
    </template>
    </div>
    </div>
@endsection
