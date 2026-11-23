@extends('layouts.app')

@section('title', 'Recibir mercadería')

@section('content')
    <a href="{{ route('compras.show', $orden) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la orden
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Recibir mercadería</h1>
        <p class="text-body-secondary small mb-0">
            Orden <span class="fw-semibold" data-numero-orden></span> a
            <span class="fw-semibold" data-proveedor-orden></span>.
            Cargá <strong>cuántas unidades llegaron de verdad</strong>: con eso se ingresa el stock, se
            recalcula el costo promedio de cada producto y queda al día el último costo de este proveedor.
        </p>
    </div>

    {{-- Shell (tramo G, regla 37): las líneas y lo pendiente llegan por `GET /api/v1/compras/{orden}` y
         registrar es `POST /api/v1/compras/{orden}/recepciones`. Del servidor sólo viene el id de la orden. --}}
    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Sin JavaScript esta pantalla no funciona.</strong>
                Las líneas de la orden y la recepción van y vienen por la API.
            </div>
        </div>
    </noscript>

    <div data-pantalla="recepcion" data-orden="{{ $orden->id }}"
        data-volver="{{ route('compras.show', $orden) }}" aria-busy="true">
        <p class="text-body-secondary" data-cargando>Cargando la orden…</p>

        <div class="card border-0 shadow-sm mb-4" data-tarjeta-recepcion hidden>
            <div class="card-body pb-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-body-secondary small d-inline-flex align-items-center gap-1">
                        <i class="bi bi-box-seam me-1" aria-hidden="true"></i> Ingresá las unidades contadas físicamente
                        <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            data-bs-title="Los campos arrancan vacíos para registrar lo efectivamente contado. Si llegó todo el pedido, usá el botón para completarlo automáticamente."
                            aria-label="Ayuda sobre recepción">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                        </button>
                    </span>

                    <button type="button" class="btn btn-sm btn-outline-dark" data-recibir-todo>
                        <i class="bi bi-check2-all" aria-hidden="true"></i> Llegó todo lo pendiente
                    </button>
                </div>
            </div>

            <form novalidate data-recepcion>
                <div class="card-body pt-0 pb-0">
                    <p class="alert alert-danger mb-3" data-errores-formulario hidden></p>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden">
                            Productos de la orden, con cuántas unidades llegaron
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Producto</th>
                                <th scope="col" class="text-end">Pedidas</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">Ya recibidas</th>
                                <th scope="col" class="text-end">Pendientes</th>
                                <th scope="col" class="text-end">Llegaron ahora</th>
                                <th scope="col" class="text-end">Quedarían</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- La plantilla va DENTRO del tbody: un <tr> en un <template> suelto
                                 no sobrevive al parseo del navegador. --}}
                            <template data-plantilla-recepcion>
                                <tr data-linea>
                                    <td>
                                        <span class="fw-semibold" data-nombre></span>
                                        <div class="small text-body-secondary" data-codigos></div>
                                    </td>

                                    <td class="text-end" data-pedidas></td>
                                    <td class="text-end d-none d-md-table-cell" data-ya-recibidas></td>
                                    <td class="text-end" data-pendientes></td>

                                    <td class="text-end" style="width: 9rem;">
                                        <label class="visually-hidden" data-rotulo-recibidas></label>
                                        {{-- Una línea completa no se puede recibir más: el JS cambia el
                                             campo por el badge, y el servicio lo rechazaría igual. --}}
                                        <span class="badge text-bg-success" data-completa hidden>Completa</span>
                                        <input type="number" class="form-control form-control-sm text-end" min="0"
                                            step="1" inputmode="numeric" data-recibidas>
                                    </td>

                                    <td class="text-end" data-quedan></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="card-body pb-0">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="remito_numero" class="form-label">Número del remito del proveedor</label>
                            <input type="text" class="form-control" id="remito_numero" name="remito_numero"
                                maxlength="30" autocomplete="off" aria-describedby="ayudaRemitoNumero">
                            <div id="ayudaRemitoNumero" class="form-text">
                                Si vino con la mercadería. Ej: 0001-00012345.
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="remito_fecha" class="form-label">Fecha del remito</label>
                            <input type="date" class="form-control" id="remito_fecha" name="remito_fecha"
                                max="{{ now()->toDateString() }}" aria-describedby="ayudaRemitoFecha">
                            <div id="ayudaRemitoFecha" class="form-text">La que figura en el remito, no la de hoy.</div>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-top pt-3 mb-3">
                        {{-- Igual que el aviso de la pantalla de ajuste: ver la consecuencia
                             antes de guardar. Un 30 escrito en lugar de un 3 se delata solo. --}}
                        <p class="small mb-0" data-total-recibido aria-live="polite"></p>

                        <p class="text-body-secondary small mb-0">
                            Pendientes en total: <span class="fw-semibold" data-pendientes-total></span>
                        </p>
                    </div>

                    <div class="d-grid d-sm-flex justify-content-sm-end gap-2">
                        <a href="{{ route('compras.show', $orden) }}" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-acento px-4">
                            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Registrar la recepción
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="alert alert-light border small text-body-secondary d-flex align-items-center gap-2">
            <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
            <div>
                Si el proveedor mandó de más, recibí lo pedido y cargá la diferencia como ajuste de inventario. Si no va a
                entregar el resto, podés usar <strong>«Cerrar con lo que llegó»</strong> en la orden.
            </div>
        </div>
    </div>
@endsection
