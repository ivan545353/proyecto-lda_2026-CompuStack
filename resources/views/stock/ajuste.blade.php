@extends('layouts.app')

@section('title', 'Ajustar inventario')

@section('content')
    <a href="{{ route('stock.index', ['producto_id' => $producto->id]) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a los movimientos
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Ajustar inventario</h1>
        <p class="text-body-secondary small mb-0">
            Se carga el <strong>conteo físico</strong>: cuántas unidades hay en el depósito.
            El sistema calcula la diferencia y la registra con tu nombre.
        </p>
    </div>

    @include('partials.errores')

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-1">{{ $producto->nombre }}</h2>
            <p class="text-body-secondary small mb-3">Código {{ $producto->codigo }}</p>

            <div class="row g-3 small">
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">En depósito</div>
                    <div class="fs-5 fw-semibold">{{ $producto->stock }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Reservadas</div>
                    <div class="fs-5">{{ $producto->stock_reservado }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Disponibles</div>
                    <div class="fs-5">{{ $producto->stock_disponible }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-body-secondary">Stock mínimo</div>
                    <div class="fs-5">{{ $producto->stock_minimo }}</div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('stock.ajuste.store', $producto) }}" novalidate
        class="card card-body border-0 shadow-sm">
        @csrf

        {{-- El stock que esta pantalla está mostrando. Si al guardar el de la base
             es otro, alguien movió el stock mientras se contaba y el servicio
             rechaza el ajuste en lugar de pisar ese movimiento. --}}
        <input type="hidden" name="stock_esperado" value="{{ $producto->stock }}">

        <div class="row g-3">
            <div class="col-12 col-md-5">
                <label for="stock_contado" class="form-label">
                    Unidades contadas <span class="text-danger" aria-hidden="true">*</span>
                </label>

                {{-- El campo arranca vacío a propósito: precargarlo con el stock
                     actual ancla el conteo y vuelve demasiado fácil confirmar un
                     número que nadie contó. --}}
                <input type="number" class="form-control @error('stock_contado') is-invalid @enderror" id="stock_contado"
                    name="stock_contado" value="{{ old('stock_contado') }}" required min="0" max="1000000"
                    step="1" autofocus inputmode="numeric"
                    aria-describedby="diferenciaAjuste ayudaContado @error('stock_contado') errorContado @enderror">

                <div id="ayudaContado" class="form-text">Lo que hay de verdad en el depósito.</div>

                <div id="diferenciaAjuste" class="form-text fw-semibold" aria-live="polite"
                    data-stock-actual="{{ $producto->stock }}"></div>

                @error('stock_contado')
                    <div id="errorContado" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-7">
                <label for="motivo" class="form-label">
                    Motivo <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <textarea class="form-control @error('motivo') is-invalid @enderror" id="motivo" name="motivo" rows="3"
                    required maxlength="255" aria-describedby="ayudaMotivo @error('motivo') errorMotivo @enderror"
                    placeholder="Por ejemplo: dos unidades con la caja rota, dadas de baja">{{ old('motivo') }}</textarea>

                <div id="ayudaMotivo" class="form-text">
                    El motivo por el qué se realizo el ajuste.
                </div>

                @error('motivo')
                    <div id="errorMotivo" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Registrar ajuste
            </button>

            <a href="{{ route('stock.index', ['producto_id' => $producto->id]) }}"
                class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
