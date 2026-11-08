@extends('layouts.app')

@use('App\Models\Impuesto')
@use('App\Support\Importe')

@php($esEdicion = $impuesto->exists)

@section('title', $esEdicion ? 'Editar impuesto' : 'Nuevo impuesto')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$impuesto->nombre}»" : 'Nuevo impuesto' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate
        action="{{ $esEdicion ? route('precios.impuestos.update', $impuesto) : route('precios.impuestos.store') }}"
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
                    name="nombre" value="{{ old('nombre', $impuesto->nombre) }}" required maxlength="60" autofocus
                    aria-describedby="ayudaNombre @error('nombre') errorNombre @enderror">

                <div id="ayudaNombre" class="form-text">
                    Es el texto que va a aparecer en el desglose del comprobante.
                </div>

                @error('nombre')
                    <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="alicuota" class="form-label">
                    Alícuota <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <div class="input-group">
                    <input type="text" inputmode="decimal" class="form-control @error('alicuota') is-invalid @enderror"
                        id="alicuota" name="alicuota"
                        value="{{ old('alicuota', Importe::numero($impuesto->alicuota, 2)) }}" required
                        aria-describedby="ayudaAlicuota @error('alicuota') errorAlicuota @enderror">
                    <span class="input-group-text">%</span>

                    @error('alicuota')
                        <div id="errorAlicuota" class="invalid-feedback">{{ $message }}</div>
                    @enderror
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

                <select class="form-select @error('alcance') is-invalid @enderror" id="alcance" name="alcance" required
                    @disabled($esEdicion) aria-describedby="ayudaAlcance @error('alcance') errorAlcance @enderror">
                    @foreach (Impuesto::ALCANCES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('alcance', $impuesto->alcance) === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>

                {{-- Un select deshabilitado no se envía, así que el valor viaja
                     igual en un campo oculto y el servidor lo vuelve a validar:
                     la pantalla no es la que decide. --}}
                @if ($esEdicion)
                    <input type="hidden" name="alcance" value="{{ $impuesto->alcance }}">
                @endif

                <div id="ayudaAlcance" class="form-text">
                    @if ($esEdicion)
                        Fijo tras la creación para proteger la consistencia del catálogo.
                    @else
                        «Por producto» para alícuotas de IVA; «Todo el catálogo» aplica a todos los productos.
                    @endif
                </div>

                @error('alcance')
                    <div id="errorAlcance" class="invalid-feedback">{{ $message }}</div>
                @enderror
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

                <select class="form-select @error('comprobante') is-invalid @enderror" id="comprobante" name="comprobante"
                    required aria-describedby="ayudaComprobante @error('comprobante') errorComprobante @enderror">
                    @foreach (Impuesto::COMPROBANTES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('comprobante', $impuesto->comprobante) === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>

                <div id="ayudaComprobante" class="form-text">
                    Discriminación legal según la normativa de facturación.
                </div>

                @error('comprobante')
                    <div id="errorComprobante" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="orden" class="form-label">Orden</label>

                <input type="text" inputmode="numeric" class="form-control @error('orden') is-invalid @enderror"
                    id="orden" name="orden" value="{{ old('orden', $impuesto->orden) }}"
                    aria-describedby="ayudaOrden @error('orden') errorOrden @enderror">

                <div id="ayudaOrden" class="form-text">En qué lugar del desglose aparece. De menor a mayor.</div>

                @error('orden')
                    <div id="errorOrden" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(old('activo', $impuesto->activo ?? true))>
                    <label class="form-check-label" for="activo">Impuesto activo</label>
                    <div class="form-text">
                        @if ($enUso)
                            Hay productos con esta alícuota. Desactivarla la saca del selector de
                            los productos nuevos; los que ya la tienen la conservan.
                        @else
                            Un impuesto inactivo no entra en ningún cálculo.
                        @endif
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
