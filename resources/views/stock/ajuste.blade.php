@extends('layouts.app')

{{-- Shell (regla 37): el producto y su stock los trae pantallas/stock.js; el ajuste va a la API. --}}

@section('title', 'Ajustar inventario')

@section('content')
    <a href="{{ route('stock.index', ['producto_id' => $productoId]) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a los movimientos
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Ajustar inventario</h1>
        <p class="text-body-secondary small mb-0">
            Se carga el <strong>conteo físico</strong>: cuántas unidades hay en el depósito.
            El sistema calcula la diferencia y la registra con tu nombre.
        </p>
    </div>

    <div class="card border-0 shadow-sm mb-4" data-stock-producto>
        <div class="card-body">
            <h2 class="h5 mb-1" data-campo="nombre">Cargando…</h2>
            <p class="text-body-secondary small mb-3">Código <span data-campo="codigo"></span></p>

            <div class="row g-3 small">
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">En depósito</div>
                    <div class="fs-5 fw-semibold" data-campo="stock"></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Reservadas</div>
                    <div class="fs-5" data-campo="stock_reservado"></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Disponibles</div>
                    <div class="fs-5" data-campo="disponible"></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Stock mínimo</div>
                    <div class="fs-5" data-campo="stock_minimo"></div>
                </div>
            </div>
        </div>
    </div>

    <form novalidate class="card card-body border-0 shadow-sm" data-formulario-ajuste-stock data-producto="{{ $productoId }}"
        data-volver="{{ route('stock.index', ['producto_id' => $productoId]) }}">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        {{-- El stock que esta pantalla está mostrando. Si al guardar el de la base es otro, alguien movió
             el stock mientras se contaba y el servicio rechaza el ajuste en lugar de pisar ese movimiento. --}}
        <input type="hidden" name="stock_esperado" value="">

        <div class="row g-3">
            <div class="col-12 col-md-5">
                <label for="stock_contado" class="form-label mb-1">
                    Unidades contadas <span class="text-danger" aria-hidden="true">*</span>
                </label>

                {{-- El campo arranca vacío a propósito: precargarlo con el stock actual ancla el conteo y
                     vuelve demasiado fácil confirmar un número que nadie contó. --}}
                <input type="number" class="form-control" id="stock_contado" name="stock_contado" required min="0"
                    max="1000000" step="1" autofocus inputmode="numeric" aria-describedby="diferenciaAjuste">

                <div id="diferenciaAjuste" class="form-text fw-semibold text-primary" aria-live="polite"></div>
            </div>

            <div class="col-12 col-md-7">
                <label for="motivo" class="form-label">
                    Motivo <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <textarea class="form-control" id="motivo" name="motivo" rows="3" required maxlength="255"
                    aria-describedby="ayudaMotivo"
                    placeholder="Por ejemplo: dos unidades con la caja rota, dadas de baja"></textarea>

                <div id="ayudaMotivo" class="form-text">
                    Explicá el motivo por el cual se realiza el ajuste.
                </div>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('stock.index', ['producto_id' => $productoId]) }}"
                class="btn btn-outline-secondary">Cancelar</a>
            {{-- Nace deshabilitado: sin el stock de referencia no hay qué guardar. --}}
            <button type="submit" class="btn btn-acento px-4" disabled>
                <i class="bi bi-check-lg" aria-hidden="true"></i> Registrar ajuste
            </button>
        </div>
    </form>
@endsection
