@extends('layouts.app')

@use('App\Models\FactorCosto')

{{-- Shell (regla 37): en la edición sólo llega el id; el factor lo trae pantallas/precios.js. --}}
@php($esEdicion = isset($factorId))

@section('title', $esEdicion ? 'Editar factor' : 'Nuevo factor')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-factor>{{ $esEdicion ? 'Editar factor' : 'Nuevo factor de costo' }}</h1>
        <p class="text-body-secondary small mb-0">
            Un factor de costo va adentro del precio y no se itemiza en el comprobante.
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" novalidate data-formulario-factor data-factor="{{ $factorId ?? '' }}"
        data-volver="{{ route('precios.index') }}" class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="60" autofocus
                    aria-describedby="ayudaNombre">

                <div id="ayudaNombre" class="form-text">Por ejemplo: Flete, o Ingresos brutos.</div>
            </div>

            {{-- El modo es la decisión que nadie tiene que recordar: un impuesto sobre la venta se recupera dividiendo. --}}
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

                <select class="form-select" id="modo" name="modo" required aria-describedby="ayudaModo">
                    @foreach (FactorCosto::MODOS as $valor => $texto)
                        <option value="{{ $valor }}" @selected($valor === 'recargo')>{{ $texto }}</option>
                    @endforeach
                </select>

                <div id="ayudaModo" class="form-text">
                    Recargo suma al costo base; recupero absorbe sobre el precio de venta.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="porcentaje" class="form-label">
                    Porcentaje <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <div class="input-group has-validation">
                    <input type="text" inputmode="decimal" class="form-control" id="porcentaje" name="porcentaje"
                        required aria-describedby="ayudaPorcentaje">
                    <span class="input-group-text">%</span>
                </div>

                <div id="ayudaPorcentaje" class="form-text">
                    Hasta tres decimales. Un recupero no puede llegar al 100 %.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="orden" class="form-label">Orden</label>

                <input type="text" inputmode="numeric" class="form-control" id="orden" name="orden" value="0"
                    aria-describedby="ayudaOrden">

                <div id="ayudaOrden" class="form-text">En qué lugar se aplica. De menor a mayor.</div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo" checked>
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
