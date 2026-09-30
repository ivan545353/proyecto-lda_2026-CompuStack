@extends('layouts.app')

@php
    $esEdicion = $direccion->exists;
    $esLaUnicaPredeterminada = $esEdicion && $direccion->es_predeterminada && $cliente->direcciones()->count() > 1;
@endphp

@section('title', $esEdicion ? 'Editar dirección' : 'Nueva dirección')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="mb-4">
                <h1 class="h3 mb-1">{{ $esEdicion ? 'Editar dirección' : 'Nueva dirección' }}</h1>
                <p class="text-body-secondary small mb-0">
                    De <span class="fw-semibold">{{ $cliente->razon_social }}</span>.
                    Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
                </p>
            </div>

            @include('partials.errores')

            <form method="POST" novalidate class="card card-body border-0 shadow-sm"
                action="{{ $esEdicion ? route('direcciones.update', [$cliente, $direccion]) : route('direcciones.store', $cliente) }}">
                @csrf
                @if ($esEdicion)
                    @method('PUT')
                @endif

                <div class="row g-3">
                    <div class="col-12 col-md-7">
                        <label for="calle" class="form-label">
                            Calle <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control @error('calle') is-invalid @enderror" id="calle"
                            name="calle" maxlength="150" autofocus value="{{ old('calle', $direccion->calle) }}"
                            aria-describedby="@error('calle') errorCalle @enderror">
                        @error('calle')
                            <div id="errorCalle" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="numero" class="form-label">
                            Altura <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control @error('numero') is-invalid @enderror" id="numero"
                            name="numero" maxlength="10" value="{{ old('numero', $direccion->numero) }}">
                        @error('numero')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="piso_depto" class="form-label">Piso y depto.</label>
                        <input type="text" class="form-control @error('piso_depto') is-invalid @enderror" id="piso_depto"
                            name="piso_depto" maxlength="20" placeholder="2 B"
                            value="{{ old('piso_depto', $direccion->piso_depto) }}">
                        @error('piso_depto')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-5">
                        <label for="localidad" class="form-label">
                            Localidad <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control @error('localidad') is-invalid @enderror" id="localidad"
                            name="localidad" maxlength="100" value="{{ old('localidad', $direccion->localidad) }}">
                        @error('localidad')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="provincia" class="form-label">
                            Provincia <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <select class="form-select @error('provincia') is-invalid @enderror" id="provincia" name="provincia"
                            data-buscable>
                            <option value="">Elegí la provincia</option>
                            @foreach (App\Models\Direccion::PROVINCIAS as $provincia)
                                <option value="{{ $provincia }}" @selected(old('provincia', $direccion->provincia) === $provincia)>{{ $provincia }}
                                </option>
                            @endforeach
                        </select>
                        @error('provincia')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="codigo_postal" class="form-label">
                            Código postal <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control @error('codigo_postal') is-invalid @enderror"
                            id="codigo_postal" name="codigo_postal" maxlength="10" placeholder="9011"
                            value="{{ old('codigo_postal', $direccion->codigo_postal) }}"
                            aria-describedby="ayudaCp @error('codigo_postal') errorCp @enderror">
                        <div id="ayudaCp" class="form-text">9011 o Z9011XAA.</div>
                        @error('codigo_postal')
                            <div id="errorCp" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="es_predeterminada"
                                name="es_predeterminada" @checked(old('es_predeterminada', $direccion->es_predeterminada))
                                aria-describedby="ayudaPredeterminada">
                            <label class="form-check-label" for="es_predeterminada">
                                Usar como dirección predeterminada
                            </label>

                            <div id="ayudaPredeterminada" class="form-text">
                                @if (!$esEdicion && $direccion->es_predeterminada)
                                    Es la primera dirección de este cliente, así que va a ser la predeterminada.
                                @elseif ($esLaUnicaPredeterminada)
                                    Hoy es la predeterminada. Destildar esto no la cambia: para cambiarla,
                                    marcá otra dirección como predeterminada.
                                @else
                                    Es la que se va a ofrecer primero al armar un envío. Marcarla desmarca
                                    la que lo sea hoy.
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-acento">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $esEdicion ? 'Guardar cambios' : 'Agregar dirección' }}
                    </button>

                    <a href="{{ route('clientes.edit', $cliente) }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
@endsection
