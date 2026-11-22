@extends('layouts.app')

{{-- Shell (regla 37): las alícuotas, la política y el cálculo los trae pantallas/precios.js. --}}

@section('title', 'Simulador de precios')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Simulador</h1>
        <p class="text-body-secondary small mb-0">
            Probá un costo y mirá qué precio sale, con el desglose completo. No guarda nada
            ni toca ningún producto.
        </p>
    </div>

    <div data-pantalla="simulador">
        {{-- GET a propósito: el resultado queda en la URL y se puede compartir. El JS lee la URL y
             le pide el cálculo a la API. --}}
        <form method="GET" action="{{ route('precios.simulador') }}" novalidate data-formulario-simulador
            class="card card-body border-0 shadow-sm mb-4">
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="row g-3 align-items-start">
                <div class="col-12 col-md-4">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="costo" class="form-label mb-0">
                            Costo neto <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            data-bs-title="Cargalo sin IVA: el IVA de compra es crédito fiscal. Si lo incluís, el precio final duplicará el impuesto."
                            aria-label="Ayuda sobre costo neto">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="input-group has-validation">
                        <span class="input-group-text">$</span>
                        <input type="text" inputmode="decimal" class="form-control" id="costo" name="costo" required
                            autofocus aria-describedby="ayudaCosto">
                    </div>

                    <div id="ayudaCosto" class="form-text">
                        Sin IVA (crédito fiscal).
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="ganancia" class="form-label">Ganancia</label>

                    <input type="text" inputmode="decimal" class="form-control" id="ganancia" name="ganancia"
                        aria-describedby="ayudaGananciaSim">

                    <div id="ayudaGananciaSim" class="form-text">
                        Vacía para usar la global<span data-ganancia-global></span>.
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="alicuota_iva" class="form-label">
                        Alícuota de IVA <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <select class="form-select" id="alicuota_iva" name="alicuota_iva" required>
                        <option value="">Cargando…</option>
                    </select>
                </div>
            </div>

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                <a href="{{ route('precios.simulador') }}" class="btn btn-outline-secondary" data-limpiar hidden>Limpiar</a>

                <button type="submit" class="btn btn-acento px-4">
                    <i class="bi bi-calculator" aria-hidden="true"></i> Calcular
                </button>
            </div>
        </form>

        {{-- Vacío por falta de datos, no por filtro: dice otra cosa. --}}
        <p class="text-body-secondary text-center py-4 mb-0" data-simulador-vacio>
            Cargá un costo y presioná «Calcular» para ver el precio con su desglose.
        </p>

        <div data-simulador-resultado hidden>
            <div class="row g-4">
                {{-- Cómo se forma el neto --}}
                <div class="col-12 col-lg-6">
                    <section class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pt-3">
                            <h2 class="h6 mb-0">Cómo se forma el neto</h2>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Del costo neto al neto de venta</caption>
                                <tbody data-formacion-neto></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                {{-- El desglose del comprobante --}}
                <div class="col-12 col-lg-6">
                    <section class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pt-3">
                            <h2 class="h6 mb-1">Precio de contado</h2>
                            <p class="text-body-secondary small mb-0">Efectivo, transferencia o QR.</p>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Desglose del precio de contado</caption>
                                <tbody data-desglose="contado"></tbody>
                            </table>
                        </div>

                        <div class="card-header bg-white border-0 border-top pt-3">
                            <h2 class="h6 mb-1">Precio de lista</h2>
                            <p class="text-body-secondary small mb-0">
                                Tarjeta y cuotas: el de contado con
                                <span data-campo="recargo_lista"></span> % de recargo adentro del neto.
                            </p>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Desglose del precio de lista</caption>
                                <tbody data-desglose="lista"></tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>

            <p class="text-body-secondary small mt-3 mb-0">
                El redondeo se aplica al precio final, múltiplo de
                <span data-campo="redondeo_multiplo"></span>,
                <span data-campo="redondeo_modo_texto"></span>.
            </p>
        </div>
    </div>
@endsection
