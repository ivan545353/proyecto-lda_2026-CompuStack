@extends('layouts.app')

@use('App\Models\Pago')

@section('title', 'Registrar una devolución')

{{-- Shell (tramo H, regla 37): los renglones, lo cobrado y el medio sugerido llegan en la ficha
     (`GET /api/v1/ventas/{venta}`, bloque `devolucion`); devolver es `POST /api/v1/ventas/{venta}/devoluciones`.
     Los medios son constantes, no datos de negocio. --}}
@section('content')
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div data-pantalla="devolucion-venta" data-venta="{{ $venta->id }}" data-ficha="{{ route('ventas.show', $venta) }}"
        aria-busy="true">
        <div class="mb-4">
            <h1 class="h3 mb-1">Registrar una devolución</h1>
            <p class="text-body-secondary small mb-0">
                Venta <span class="fw-semibold" data-campo="numero"></span> a
                <span class="fw-semibold" data-campo="cliente_texto"></span>.
                Cargá <strong>cuántas unidades vuelven</strong> de cada renglón: con eso el stock
                vuelve al depósito, se devuelve la plata que corresponde y la venta cambia de estado.
            </p>
        </div>

        <p class="text-body-secondary" data-cargando>Cargando la venta…</p>

        <form novalidate data-formulario-cantidades
            data-confirmar="La devolución no se puede deshacer: el stock vuelve al depósito y la plata se devuelve. ¿Seguimos?"
            hidden>
            <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body pb-2">
                    <span class="text-body-secondary small d-inline-flex align-items-center gap-1">
                        <i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i> Ingresá las unidades físicas que devolvió el cliente
                        <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            data-bs-title="Los campos arrancan vacíos para registrar lo efectivamente traído. Mueve stock de vuelta al depósito y registra el reintegro de dinero."
                            aria-label="Ayuda sobre devoluciones">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                        </button>
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden">
                            Productos de la venta, con cuántas unidades vuelven
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Producto</th>
                                <th scope="col" class="text-end">Vendidas</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">Ya devueltas</th>
                                <th scope="col" class="text-end">Pendientes</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">Precio unitario</th>
                                <th scope="col" class="text-end">Vuelven ahora</th>
                            </tr>
                        </thead>
                        <tbody data-lineas></tbody>
                    </table>
                </div>

                <template data-plantilla-linea>
                    <tr>
                        <td>
                            {{-- La descripción congelada: sigue diciendo qué se
                                 vendió aunque el producto se haya renombrado. --}}
                            <span class="fw-semibold" data-campo="descripcion"></span>
                            <div class="small text-body-secondary" data-campo="codigo"></div>
                        </td>

                        <td class="text-end" data-campo="cantidad"></td>
                        <td class="text-end d-none d-md-table-cell" data-campo="hecho"></td>
                        <td class="text-end" data-campo="pendiente"></td>
                        <td class="text-end text-nowrap d-none d-md-table-cell" data-campo="precio_unitario"></td>

                        <td class="text-end" style="width: 9rem;">
                            {{-- Un renglón ya devuelto por completo no admite más, así que no se
                                 ofrece el campo; el servicio lo rechazaría igual. --}}
                            <span class="badge text-bg-warning" data-si="completa">Devuelta</span>

                            <div data-si="abierta">
                                <label class="visually-hidden" for="cantidad___ID__" data-rotulo-cantidad>
                                    Unidades que vuelven
                                </label>
                                <input type="number" class="form-control form-control-sm text-end"
                                    id="cantidad___ID__" value="" min="0" step="1" inputmode="numeric" data-cantidad>
                            </div>
                        </td>
                    </tr>
                </template>

                <div class="card-body border-top">
                    <p class="text-body-secondary small mb-0">
                        Pendientes de devolver en total: <span class="fw-semibold" data-campo="pendientes"></span> unidad(es).
                    </p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <div class="card card-body border-0 shadow-sm h-100">
                        <h2 class="h6 text-body-secondary">La plata que vuelve</h2>

                        <dl class="row small mb-2">
                            <dt class="col-7 fw-normal text-body-secondary">Total de la venta</dt>
                            <dd class="col-5 text-end mb-1" data-campo="total"></dd>

                            <dt class="col-7 fw-normal text-body-secondary" data-si="descuento" hidden>
                                Descuento aplicado
                            </dt>
                            <dd class="col-5 text-end mb-1" data-si="descuento" hidden>
                                <span data-campo="descuento_porcentaje"></span> %
                            </dd>

                            <dt class="col-7 fw-semibold border-top pt-2">Quedó cobrado</dt>
                            <dd class="col-5 text-end fw-semibold border-top pt-2 mb-0" data-campo="pagado"></dd>
                        </dl>

                        <p class="text-body-secondary small mb-0">
                            <strong>El monto lo calcula el sistema</strong> y no se escribe: es el precio de
                            las unidades que vuelven<span data-si="descuento" hidden> menos su parte proporcional
                            del descuento, para que devolver de a poco no salga más caro que devolver todo
                            junto</span>. Si esta devolución completa la venta, vuelve exactamente lo que quedó
                            cobrado, para que el libro cierre en cero.
                        </p>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <div class="card card-body border-0 shadow-sm h-100">
                        <h2 class="h6 text-body-secondary">Cómo vuelve</h2>

                        <div class="mb-3">
                            <label for="metodo" class="form-label">Medio por el que vuelve la plata</label>

                            {{-- Sin `required`: el JS elige el sugerido de la ficha, así que el
                                 selector nunca está sin elegir. --}}
                            <select class="form-select" id="metodo" name="metodo" aria-describedby="ayudaMetodo">
                                @foreach (Pago::METODOS_EN_USO as $clave)
                                    <option value="{{ $clave }}">{{ Pago::METODOS[$clave] }}</option>
                                @endforeach
                            </select>

                            <div id="ayudaMetodo" class="form-text">
                                Cambiá el medio si devolvés el dinero por otra vía.
                            </div>
                        </div>

                        <div>
                            <label for="motivo" class="form-label">
                                Motivo <span class="text-body-secondary fw-normal">(opcional)</span>
                            </label>

                            <textarea class="form-control" id="motivo" name="motivo" rows="2"
                                maxlength="255" aria-describedby="ayudaMotivo"
                                placeholder="Vino fallado, no era el modelo que pidió…"></textarea>

                            <div id="ayudaMotivo" class="form-text">
                                Se registra en el kardex de stock de cada producto reintegrado.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="alert alert-secondary d-flex align-items-start gap-2 small py-2 px-3 mt-4 mb-0 border-0 bg-body-tertiary">
                <i class="bi bi-info-circle text-body-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div>
                    El estado (<strong>parcial</strong> o <strong>total</strong>) se calcula automáticamente según las unidades. La venta original no se anula: se asienta el reintegro correspondiente.
                </div>
            </div>

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-danger px-4">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Registrar devolución
                </button>
            </div>
        </form>
    </div>
@endsection
