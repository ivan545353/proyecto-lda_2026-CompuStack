@extends('layouts.app')

@use('App\Models\Impuesto')

{{-- Shell (regla 37): en la edición sólo llega el id; el impuesto lo trae pantallas/precios.js. --}}
@php($esEdicion = isset($impuestoId))

@section('title', $esEdicion ? 'Editar impuesto' : 'Nuevo impuesto')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-impuesto>{{ $esEdicion ? 'Editar impuesto' : 'Nuevo impuesto' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" novalidate data-formulario-impuesto data-impuesto="{{ $impuestoId ?? '' }}"
        data-volver="{{ route('precios.index') }}" class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="60" autofocus
                    aria-describedby="ayudaNombre">

                <div id="ayudaNombre" class="form-text">
                    Es el texto que va a aparecer en el desglose del comprobante.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="alicuota" class="form-label">
                    Alícuota <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <div class="input-group has-validation">
                    <input type="text" inputmode="decimal" class="form-control" id="alicuota" name="alicuota" required
                        aria-describedby="ayudaAlicuota">
                    <span class="input-group-text">%</span>
                </div>

                <div id="ayudaAlicuota" class="form-text">Puede ser cero: un producto exento igual declara su IVA en cero.
                </div>
            </div>

            {{-- El alcance sólo se elige al crear: ver la ayuda. --}}
            <div class="col-12 col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="alcance" class="form-label mb-0">
                        Alcance <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    @if ($esEdicion)
                        <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            data-bs-title="El alcance no se modifica para evitar que los productos paguen IVA dos veces. Creá otro impuesto y desactivá este si hace falta."
                            aria-label="Información sobre el alcance del impuesto">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                        </button>
                    @endif
                </div>

                {{-- Deshabilitado en la edición: el JS manda el valor que trajo la ficha, y el servidor lo
                     vuelve a validar. La pantalla no es la que decide. --}}
                <select class="form-select" id="alcance" name="alcance" required @disabled($esEdicion)
                    aria-describedby="ayudaAlcance">
                    @foreach (Impuesto::ALCANCES as $valor => $texto)
                        <option value="{{ $valor }}" @selected($valor === 'general')>{{ $texto }}</option>
                    @endforeach
                </select>

                <div id="ayudaAlcance" class="form-text">
                    @if ($esEdicion)
                        Fijo tras la creación para proteger la consistencia del catálogo.
                    @else
                        «Por producto» para alícuotas de IVA; «Todo el catálogo» aplica a todos los productos.
                    @endif
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="comprobante" class="form-label mb-0">
                        En el comprobante <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="La Ley 27.743 exige discriminar el IVA y otros impuestos nacionales indirectos en ventas a consumidor final."
                        aria-label="Normativa de comprobantes">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                <select class="form-select" id="comprobante" name="comprobante" required aria-describedby="ayudaComprobante">
                    @foreach (Impuesto::COMPROBANTES as $valor => $texto)
                        <option value="{{ $valor }}" @selected($valor === 'otros_nacionales')>{{ $texto }}</option>
                    @endforeach
                </select>

                <div id="ayudaComprobante" class="form-text">
                    Discriminación legal según la normativa de facturación.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="orden" class="form-label">Orden</label>

                <input type="text" inputmode="numeric" class="form-control" id="orden" name="orden" value="0"
                    aria-describedby="ayudaOrden">

                <div id="ayudaOrden" class="form-text">En qué lugar del desglose aparece. De menor a mayor.</div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo" checked>
                    <label class="form-check-label" for="activo">Impuesto activo</label>
                    <div class="form-text">
                        <span data-ayuda-en-uso hidden>
                            Hay productos con esta alícuota. Desactivarla la saca del selector de
                            los productos nuevos; los que ya la tienen la conservan.
                        </span>
                        <span data-ayuda-sin-uso>Un impuesto inactivo no entra en ningún cálculo.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('precios.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear impuesto' }}
            </button>
        </div>
    </form>
@endsection
