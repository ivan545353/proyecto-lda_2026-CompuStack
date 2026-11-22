@extends('layouts.app')

{{-- Shell (regla 37): sólo llegan los ids; el cliente y la dirección los trae pantallas/direcciones.js. --}}
@php($esEdicion = isset($direccionId))

@section('title', $esEdicion ? 'Editar dirección' : 'Nueva dirección')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="mb-4">
                <h1 class="h3 mb-1">{{ $esEdicion ? 'Editar dirección' : 'Nueva dirección' }}</h1>
                <p class="text-body-secondary small mb-0">
                    De <span class="fw-semibold" data-campo="cliente"></span>.
                    Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
                </p>
            </div>

            {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
            <form method="POST" novalidate data-formulario-direccion data-cliente="{{ $clienteId }}"
                data-direccion="{{ $direccionId ?? '' }}"
                data-volver="{{ route('clientes.edit', ['cliente' => $clienteId]) }}"
                class="card card-body border-0 shadow-sm">
                <p class="alert alert-danger" data-errores-formulario hidden></p>

                <div class="row g-3">
                    <div class="col-12 col-md-7">
                        <label for="calle" class="form-label">
                            Calle <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control" id="calle"
                            name="calle" maxlength="150" autofocus>
                    </div>

                    <div class="col-6 col-md-2">
                        <label for="numero" class="form-label">
                            Altura <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control" id="numero"
                            name="numero" maxlength="10">
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="piso_depto" class="form-label">Piso y depto.</label>
                        <input type="text" class="form-control" id="piso_depto"
                            name="piso_depto" maxlength="20" placeholder="2 B">
                    </div>

                    <div class="col-12 col-md-5">
                        <label for="localidad" class="form-label">
                            Localidad <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control" id="localidad"
                            name="localidad" maxlength="100">
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="provincia" class="form-label">
                            Provincia <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <select class="form-select" id="provincia" name="provincia"
                            data-buscable>
                            <option value="">Elegí la provincia</option>
                            @foreach (App\Models\Direccion::PROVINCIAS as $provincia)
                                <option value="{{ $provincia }}">{{ $provincia }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="codigo_postal" class="form-label">
                            Código postal <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <input type="text" class="form-control"
                            id="codigo_postal" name="codigo_postal" maxlength="10" placeholder="9011"
                            aria-describedby="ayudaCp">
                        <div id="ayudaCp" class="form-text">Formato: 9011 o Z9011XAA.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="es_predeterminada"
                                name="es_predeterminada"
                                aria-describedby="ayudaPredeterminada">
                            <label class="form-check-label" for="es_predeterminada">
                                Usar como dirección predeterminada
                            </label>

                            <div id="ayudaPredeterminada" class="form-text">
                                {{-- Lo que dice depende de las direcciones que ya tiene: lo arma el JS con la ficha. --}}
                                <span data-ayuda-predeterminada></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                    <a href="{{ route('clientes.edit', ['cliente' => $clienteId]) }}" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $esEdicion ? 'Guardar cambios' : 'Agregar dirección' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
