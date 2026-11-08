@extends('layouts.app')

@use('App\Models\FactorCosto')
@use('App\Support\Importe')

@php($esEdicion = $factor->exists)

@section('title', $esEdicion ? 'Editar factor' : 'Nuevo factor')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$factor->nombre}»" : 'Nuevo factor de costo' }}</h1>
        <p class="text-body-secondary small mb-0">
            Un factor de costo va adentro del precio y no se itemiza en el comprobante.
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate
        action="{{ $esEdicion ? route('precios.factores.update', $factor) : route('precios.factores.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text" class="form-control @error('nombre') is-invalid @enderror" id="nombre"
                    name="nombre" value="{{ old('nombre', $factor->nombre) }}" required maxlength="60" autofocus
                    aria-describedby="ayudaNombre @error('nombre') errorNombre @enderror">

                <div id="ayudaNombre" class="form-text">Por ejemplo: Flete, o Ingresos brutos.</div>

                @error('nombre')
                    <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- El modo es la decisión que el tramo quiere que nadie tenga que
                 recordar: un impuesto sobre la venta se recupera dividiendo. --}}
            <div class="col-12 col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="modo" class="form-label mb-0">
                        Modo <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Un costo que se suma (como flete) es un recargo. Un impuesto sobre la venta es un recupero: para recuperar el 3 % exacto, divide por 0,97 en vez de multiplicar."
                        aria-label="Ayuda sobre modos de factor">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                <select class="form-select @error('modo') is-invalid @enderror" id="modo" name="modo" required
                    aria-describedby="ayudaModo @error('modo') errorModo @enderror">
                    @foreach (FactorCosto::MODOS as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('modo', $factor->modo) === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>

                <div id="ayudaModo" class="form-text">
                    Recargo suma al costo base; recupero absorbe sobre el precio de venta.
                </div>

                @error('modo')
                    <div id="errorModo" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="porcentaje" class="form-label">
                    Porcentaje <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <div class="input-group">
                    <input type="text" inputmode="decimal" class="form-control @error('porcentaje') is-invalid @enderror"
                        id="porcentaje" name="porcentaje"
                        value="{{ old('porcentaje', Importe::numero($factor->porcentaje)) }}" required
                        aria-describedby="ayudaPorcentaje @error('porcentaje') errorPorcentaje @enderror">
                    <span class="input-group-text">%</span>

                    @error('porcentaje')
                        <div id="errorPorcentaje" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div id="ayudaPorcentaje" class="form-text">
                    Hasta tres decimales. Un recupero no puede llegar al 100 %.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="orden" class="form-label">Orden</label>

                <input type="text" inputmode="numeric" class="form-control @error('orden') is-invalid @enderror"
                    id="orden" name="orden" value="{{ old('orden', $factor->orden) }}"
                    aria-describedby="ayudaOrden @error('orden') errorOrden @enderror">

                <div id="ayudaOrden" class="form-text">En qué lugar se aplica. De menor a mayor.</div>

                @error('orden')
                    <div id="errorOrden" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(old('activo', $factor->activo ?? true))>
                    <label class="form-check-label" for="activo">Factor activo</label>
                    <div class="form-text">
                        Un factor inactivo no entra en ningún cálculo, pero queda cargado para
                        volver a usarlo.
                    </div>
                </div>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('precios.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear factor' }}
            </button>
        </div>
    </form>
@endsection
