@extends('layouts.app')

@php($esEdicion = $marca->exists)

@section('title', $esEdicion ? 'Editar marca' : 'Nueva marca')

@section('content')
    <a href="{{ route('marcas.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$marca->nombre}»" : 'Nueva marca' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Resumen general de errores de validación --}}
    @include('partials.errores')

    {{-- Formulario principal (soporta subida de archivos multipart) --}}
    <form method="POST" enctype="multipart/form-data" novalidate
        action="{{ $esEdicion ? route('marcas.update', $marca) : route('marcas.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="row g-3">
            {{-- Nombre de la marca --}}
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text" class="form-control @error('nombre') is-invalid @enderror" id="nombre"
                    name="nombre" value="{{ old('nombre', $marca->nombre) }}" required maxlength="100" autofocus
                    aria-describedby="ayudaNombre @error('nombre') errorNombre @enderror">

                <div id="ayudaNombre" class="form-text">Entre 2 y 100 caracteres. No puede repetirse.</div>

                @error('nombre')
                    <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Logotipo opcional (con soporte para reemplazo y eliminación) --}}
            <div class="col-12 col-md-6">
                <span class="form-label d-block">Logo</span>

                <x-gestor-imagenes :actuales="$marca->logo ? [$marca->logo] : []" :max="1" :max-kb="512" campo="logo"
                    campo-quitar="quitar_logo" etiqueta-agregar="Agregar logo" :descripcion="$marca->nombre ?: 'la marca'" />
            </div>

            {{-- Estado de disponibilidad en catálogo --}}
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(old('activo', $marca->activo ?? true))>
                    <label class="form-check-label" for="activo">Marca activa</label>
                    <div class="form-text">Una marca inactiva no se ofrece al dar de alta nuevos productos.</div>
                </div>
            </div>
        </div>

        {{-- Acciones del formulario --}}
        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear marca' }}
            </button>

            <a href="{{ route('marcas.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
