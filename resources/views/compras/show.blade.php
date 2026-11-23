@extends('layouts.app')

@section('title', 'Orden de compra')

{{-- Shell (tramo G, regla 37): la orden llega por `GET /api/v1/compras/{orden}`. Cada acción tiene su
     `@can` (regla 12) y nace oculta; la muestra el JS si `acciones` dice que el estado la admite. Ninguna
     de las dos cosas es autoridad: la API vuelve a decidir en cada petición. --}}
@section('content')
    <div data-pantalla="orden-compra" data-orden="{{ $orden->id }}" aria-busy="true">
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <p class="text-body-secondary" data-cargando>Cargando la orden…</p>

    <div data-ficha hidden>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">
                Orden <span data-campo="numero"></span>
                {{-- El estado nunca se comunica sólo con color: el badge lleva texto. --}}
                <span class="badge align-middle ms-2" data-campo="estado_texto"></span>
            </h1>
            <p class="text-body-secondary small mb-0">
                A <span class="fw-semibold" data-campo="proveedor"></span>, por
                <span data-campo="total_estimado"></span>.
                <span data-generada hidden>La generó la tarea de reposición.</span>
            </p>
        </div>

        @can('compra.editar')
            <a href="{{ route('compras.edit', $orden->id) }}" class="btn btn-outline-dark" data-accion="editar" hidden>
                <i class="bi bi-pencil" aria-hidden="true"></i> Editar el borrador
            </a>
        @endcan
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Proveedor</h2>

                <p class="mb-1 fw-semibold" data-campo="proveedor"></p>

                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal text-body-secondary" data-si="cuit">CUIT</dt>
                    <dd class="col-7 mb-1" data-si="cuit" data-campo="cuit"></dd>

                    <dt class="col-5 fw-normal text-body-secondary" data-si="contacto">Contacto</dt>
                    <dd class="col-7 mb-1" data-si="contacto" data-campo="contacto"></dd>

                    <dt class="col-5 fw-normal text-body-secondary">Cómo se le pide</dt>
                    <dd class="col-7 mb-1" data-campo="canal_pedido_texto"></dd>

                    <dt class="col-5 fw-normal text-body-secondary" data-si="plazo">Plazo de entrega</dt>
                    <dd class="col-7 mb-0" data-si="plazo"><span data-campo="plazo"></span> días</dd>
                </dl>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Quién y cuándo</h2>

                <dl class="row small mb-0">
                    <dt class="col-5 fw-normal text-body-secondary">Creada</dt>
                    {{-- Sin quien la creara, «por el sistema»: se dice con palabras, no con una celda vacía. --}}
                    <dd class="col-7 mb-1" data-campo="creada"></dd>

                    <dt class="col-5 fw-normal text-body-secondary">Aprobada</dt>
                    <dd class="col-7 mb-1" data-campo="aprobada"></dd>

                    <dt class="col-5 fw-normal text-body-secondary">Enviada</dt>
                    <dd class="col-7 mb-0" data-campo="enviada"></dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- El canal de pedido es un dato descriptivo, no un despachador: el sistema no manda nada. Acá se
         usa para decir cuál es el próximo paso, que es lo único que cambia entre los tres canales. --}}
    <div class="alert alert-info" data-que-sigue hidden>
        <h2 class="h6">Qué sigue</h2>

        <p class="mb-0" data-canal="email" hidden>
            Descargá el pedido y mandáselo por correo<span data-si="email"> a <strong data-campo="email"></strong></span>.
            Después marcá la orden como enviada.
        </p>
        <p class="mb-0" data-canal="portal_externo" hidden>
            Descargá el pedido y cargalo en el portal del proveedor<span data-si="portal_url">
            —<a href="#" target="_blank" rel="noopener noreferrer" data-campo="portal_url" data-enlace-portal></a>—</span>.
            Después marcá la orden como enviada.
        </p>
        <p class="mb-0" data-canal="otro" hidden>
            Descargá el pedido y pasáselo por teléfono o WhatsApp<span data-si="telefono"> al <strong data-campo="telefono"></strong></span>.
            Después marcá la orden como enviada.
        </p>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Productos de la orden</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Pedidas</th>
                        <th scope="col" class="text-end">Recibidas</th>
                        <th scope="col" class="text-end">Pendientes</th>
                        <th scope="col" class="text-end">Costo unitario</th>
                        <th scope="col" class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody data-lineas></tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Total estimado</td>
                        <td class="text-end fw-semibold text-nowrap" data-campo="total_estimado"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <template data-plantilla-linea>
        <tr>
            <td>
                <span class="fw-semibold" data-campo="nombre"></span>
                {{-- El código del proveedor queda congelado en la línea: el pedido se imprime con el código
                     que ese proveedor entiende, aunque después renumere su catálogo. --}}
                <div class="small text-body-secondary">
                    <span data-campo="codigo"></span><span data-si="codigo_proveedor"> · para el proveedor:
                    <span data-campo="codigo_proveedor"></span></span>
                </div>
            </td>
            <td class="text-end" data-campo="cantidad_pedida"></td>
            <td class="text-end" data-campo="cantidad_recibida"></td>
            <td class="text-end">
                <span class="badge text-bg-warning" data-si="pendiente" data-campo="cantidad_pendiente"></span>
                <span class="text-body-secondary" data-si="completa">—</span>
            </td>
            <td class="text-end text-nowrap" data-campo="costo_unitario"></td>
            <td class="text-end text-nowrap" data-campo="subtotal"></td>
        </tr>
    </template>

    <div class="card card-body border-0 shadow-sm mb-4" data-si="observaciones">
        <h2 class="h6 text-body-secondary">Observaciones</h2>
        <p class="mb-0" data-campo="observaciones"></p>
    </div>

    <div class="card card-body border-0 shadow-sm mb-4">
        <h2 class="h5 mb-3">Acciones</h2>

        <div class="d-grid d-sm-flex flex-wrap gap-2 mb-3">
            {{-- La ruta ya exige compra.ver: no hace falta otro @can. El PDF va por enlace firmado (D-22). --}}
            <button type="button" class="btn btn-outline-dark" data-accion="imprimir" hidden>
                <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Descargar el pedido
            </button>
        </div>

        {{-- Un estado final no tiene salidas en la tabla de transiciones, y la pantalla no inventa botones
             que el servidor va a rechazar. --}}
        <p class="text-body-secondary mb-0" data-sin-salidas hidden>
            Esta orden está «<span data-campo="estado_texto"></span>» y no admite más cambios de estado.
        </p>

        <div class="d-grid d-sm-flex flex-wrap gap-2" data-salidas>
            @can('compra.aprobar')
                <button type="button" class="btn btn-acento" data-accion="aprobar" hidden>
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Aprobar
                </button>
            @endcan

            @can('compra.aprobar')
                <button type="button" class="btn btn-outline-dark" data-accion="enviar" hidden>
                    <i class="bi bi-send" aria-hidden="true"></i> Marcar como enviada
                </button>
            @endcan

            @can('compra.recibir')
                <a href="{{ route('compras.recepcion', $orden->id) }}" class="btn btn-outline-dark" data-accion="recibir" hidden>
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Recibir mercadería
                </a>
            @endcan

            @can('compra.recibir')
                <button type="button" class="btn btn-outline-dark" data-accion="cerrar" hidden>
                    <i class="bi bi-box-seam" aria-hidden="true"></i> Cerrar con lo que llegó
                </button>
            @endcan

            @can('compra.aprobar')
                <button type="button" class="btn btn-outline-danger" data-accion="cancelar" hidden>
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Cancelar la orden
                </button>
            @endcan
        </div>
    </div>

    <div class="card border-0 shadow-sm" data-si="recepciones">
        <div class="card-body pb-3">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Lo que entró por esta orden</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Movimientos del kardex con fecha, unidades y usuario responsable."
                    aria-label="Información sobre movimientos de stock de la orden">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">Movimientos de stock generados por esta orden</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Cuándo</th>
                        <th scope="col" class="d-none d-md-table-cell">Remito</th>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Unidades</th>
                        <th scope="col" class="text-end">Stock</th>
                        <th scope="col">Quién</th>
                    </tr>
                </thead>
                <tbody data-recepciones></tbody>
            </table>
        </div>
    </div>

    <template data-plantilla-recepcion>
        <tr>
            <td class="text-nowrap" data-campo="cuando"></td>
            <td class="d-none d-md-table-cell text-nowrap">
                <span data-campo="remito_texto"></span>
                <span class="text-body-secondary" data-si="sin_remito">No consta</span>
            </td>
            <td>
                <span data-campo="producto"></span>
                <div class="small text-body-secondary" data-campo="codigo"></div>
            </td>
            <td class="text-end text-nowrap fw-semibold text-success" data-campo="cantidad"></td>
            <td class="text-end text-nowrap">
                <span aria-hidden="true">
                    <span class="text-body-secondary" data-campo="stock_anterior"></span>
                    →
                    <span class="fw-semibold" data-campo="stock_resultante"></span>
                </span>
                <span class="visually-hidden" data-campo="stock_leido"></span>
            </td>
            <td data-campo="registrado_por"></td>
        </tr>
    </template>
    </div>
    </div>
@endsection
