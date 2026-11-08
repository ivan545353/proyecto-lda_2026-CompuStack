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

        @include('clientes._campos', ['cliente' => $cliente])

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

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear cliente' }}
            </button>
        </div>
    </form>

    {{-- Direcciones. Sólo en la edición: no se le puede colgar una dirección a un
         cliente que todavía no existe. --}}
    @if ($esEdicion)
        <div class="card card-body border-0 shadow-sm mt-4">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Direcciones</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Se utilizan para los envíos. La predeterminada se ofrecerá en primer lugar."
                        aria-label="Información sobre direcciones de envío">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
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
