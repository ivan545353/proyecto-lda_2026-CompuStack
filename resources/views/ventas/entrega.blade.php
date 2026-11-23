@extends('layouts.app')

@section('title', 'Entregar una parte')

{{-- Shell (tramo H, regla 37): los renglones y lo pendiente llegan en la ficha (`GET /api/v1/ventas/{venta}`);
     entregar es `POST /api/v1/ventas/{venta}/entregas` con `cantidades`. --}}
@section('content')
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div data-pantalla="entrega-venta" data-venta="{{ $venta->id }}" data-ficha="{{ route('ventas.show', $venta) }}"
        aria-busy="true">
        <div class="mb-4">
            <h1 class="h3 mb-1">Entregar una parte</h1>
            <p class="text-body-secondary small mb-0">
                Venta <span class="fw-semibold" data-campo="numero"></span> a
                <span class="fw-semibold" data-campo="cliente_texto"></span>.
                Cargá <strong>cuántas unidades salen ahora</strong> de cada renglón; lo demás queda pendiente
                y se entrega después.
            </p>
        </div>

        <p class="text-body-secondary" data-cargando>Cargando la venta…</p>

        <form novalidate data-formulario-cantidades hidden>
            <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden">
                            Productos de la venta, con cuántas unidades salen ahora
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Producto</th>
                                <th scope="col" class="text-end">Vendidas</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">Ya entregadas</th>
                                <th scope="col" class="text-end">Pendientes</th>
                                <th scope="col" class="text-end">Salen ahora</th>
                            </tr>
                        </thead>
                        <tbody data-lineas></tbody>
                    </table>
                </div>

                <template data-plantilla-linea>
                    <tr>
                        <td>
                            <span class="fw-semibold" data-campo="descripcion"></span>
                            <div class="small text-body-secondary" data-campo="codigo"></div>
                        </td>

                        <td class="text-end" data-campo="cantidad"></td>
                        <td class="text-end d-none d-md-table-cell" data-campo="hecho"></td>
                        <td class="text-end" data-campo="pendiente"></td>

                        <td class="text-end" style="width: 9rem;">
                            <span class="badge text-bg-success" data-si="completa">Entregada</span>

                            <div data-si="abierta">
                                <label class="visually-hidden" for="cantidad___ID__" data-rotulo-cantidad>
                                    Unidades que salen ahora
                                </label>
                                <input type="number" class="form-control form-control-sm text-end"
                                    id="cantidad___ID__" value="" min="0" step="1" inputmode="numeric" data-cantidad>
                            </div>
                        </td>
                    </tr>
                </template>

                <div class="card-body border-top">
                    <p class="text-body-secondary small mb-0">
                        Pendientes de entregar en total: <span class="fw-semibold" data-campo="pendientes"></span> unidad(es).
                        Si la entrega completa la venta, queda entregada; si no, entregada en parte.
                    </p>
                </div>
            </div>

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-acento px-4">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Registrar la entrega
                </button>
            </div>
        </form>
    </div>
@endsection
