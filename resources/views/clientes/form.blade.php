@extends('layouts.app')

@php($esEdicion = $cliente->exists)

@section('title', $esEdicion ? 'Editar cliente' : 'Nuevo cliente')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">
            {{ $esEdicion ? "Editar «{$cliente->razon_social}»" : 'Nuevo cliente' }}
        </h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ $esEdicion ? route('clientes.update', $cliente) : route('clientes.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <h2 class="h5 mb-3">Datos para facturar</h2>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="razon_social" class="form-label">
                    Nombre o razón social <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control @error('razon_social') is-invalid @enderror" id="razon_social"
                    name="razon_social" maxlength="150" autofocus value="{{ old('razon_social', $cliente->razon_social) }}"
                    aria-describedby="@error('razon_social') errorRazon @enderror">
                @error('razon_social')
                    <div id="errorRazon" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="condicion_iva" class="form-label">
                    Condición frente al IVA <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select @error('condicion_iva') is-invalid @enderror" id="condicion_iva"
                    name="condicion_iva" aria-describedby="ayudaIva @error('condicion_iva') errorIva @enderror">
                    @foreach (App\Models\Cliente::CONDICIONES_IVA as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('condicion_iva', $cliente->condicion_iva) === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
                <div id="ayudaIva" class="form-text">
                    Responsable inscripto lleva Factura A; el resto, Factura B.
                </div>
                @error('condicion_iva')
                    <div id="errorIva" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-3">
                <label for="tipo_doc" class="form-label">
                    Tipo de documento <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select @error('tipo_doc') is-invalid @enderror" id="tipo_doc" name="tipo_doc">
                    @foreach (App\Models\Cliente::TIPOS_DOC as $valor => $texto)
                        <option value="{{ $valor }}" @selected(old('tipo_doc', $cliente->tipo_doc) === $valor)>
                            {{ $texto }}</option>
                    @endforeach
                </select>
                @error('tipo_doc')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-3">
                <label for="nro_doc" class="form-label">Número de documento</label>
                <input type="text" inputmode="numeric" class="form-control @error('nro_doc') is-invalid @enderror"
                    id="nro_doc" name="nro_doc" value="{{ old('nro_doc', $cliente->nro_doc) }}"
                    aria-describedby="ayudaDoc @error('nro_doc') errorDoc @enderror">
                <div id="ayudaDoc" class="form-text">
                    Lo podés escribir con guiones. Obligatorio si factura A o es monotributista.
                </div>
                @error('nro_doc')
                    <div id="errorDoc" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-3">
                <label for="email" class="form-label">Correo</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email"
                    name="email" maxlength="150" value="{{ old('email', $cliente->email) }}">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-3">
                <label for="telefono" class="form-label">Teléfono</label>
                <input type="text" class="form-control @error('telefono') is-invalid @enderror" id="telefono"
                    name="telefono" maxlength="30" value="{{ old('telefono', $cliente->telefono) }}">
                @error('telefono')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        {{-- La cuenta de acceso no se administra desde acá ni desde Personal: la
             pantalla de personal lista sólo ámbito gestión. Administrar cuentas de
             la tienda llega con la tienda, en la Etapa 2. --}}
        @if ($esEdicion && !$cliente->esDeMostrador())
            <div class="alert alert-secondary mt-4 mb-0" role="alert">
                <i class="bi bi-person-check" aria-hidden="true"></i>
                Este cliente tiene cuenta de la tienda ({{ $cliente->usuario->email }}).
                En esta etapa la tienda todavía no está publicada, así que esa cuenta no se
                usa para entrar al sistema de gestión.
            </div>
        @endif

        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear cliente' }}
            </button>

            <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    {{-- Direcciones. Sólo en la edición: no se le puede colgar una dirección a un
         cliente que todavía no existe. --}}
    @if ($esEdicion)
        <div class="card card-body border-0 shadow-sm mt-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                <div>
                    <h2 class="h5 mb-1">Direcciones</h2>
                    <p class="text-body-secondary small mb-0">
                        Se usan para los envíos. Una es la predeterminada: es la que se va a ofrecer primero.
                    </p>
                </div>

                <a href="{{ route('direcciones.create', $cliente) }}" class="btn btn-outline-dark">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar dirección
                </a>
            </div>

            @forelse ($cliente->direcciones as $direccion)
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap border-top py-3">
                    <div>
                        <div>{{ $direccion->resumen() }}</div>

                        @if ($direccion->es_predeterminada)
                            <span class="badge text-bg-dark mt-1">Predeterminada</span>
                        @endif
                    </div>

                    <div class="text-nowrap">
                        <a href="{{ route('direcciones.edit', [$cliente, $direccion]) }}"
                            class="btn btn-sm btn-outline-dark">
                            <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                        </a>

                        <form method="POST" action="{{ route('direcciones.destroy', [$cliente, $direccion]) }}"
                            class="d-inline" onsubmit="return confirm(@js($direccion->es_predeterminada && $cliente->direcciones->count() > 1 ? "¿Eliminar «{$direccion->resumen()}»? Es la predeterminada: la más antigua de las que quedan va a pasar a serlo." : "¿Eliminar «{$direccion->resumen()}»?"));">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"
                                aria-label="Eliminar la dirección {{ $direccion->resumen() }}">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-body-secondary small border-top pt-3 mb-0">
                    Todavía no tiene direcciones cargadas. Hacen falta sólo si se le va a enviar mercadería.
                </p>
            @endforelse
        </div>
    @endif
@endsection
