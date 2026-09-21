@extends('layouts.app')

@php($esEdicion = $marca->exists)

@section('title', $esEdicion ? 'Editar marca' : 'Nueva marca')

@section('content')
    {{-- Encabezado del formulario con contexto de navegación --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$marca->nombre}»" : 'Nueva marca' }}</h1>
            <p class="text-body-secondary small mb-0">
                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
            </p>
        </div>
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
                <label for="logo" class="form-label">Logo</label>

                <input type="file" class="form-control @error('logo') is-invalid @enderror" id="logo" name="logo"
                    accept="image/jpeg,image/png,image/webp"
                    aria-describedby="ayudaLogo @error('logo') errorLogo @enderror">

                <div id="ayudaLogo" class="form-text">JPG, PNG o WEBP. Hasta 512 KB. Opcional.</div>

                @error('logo')
                    <div id="errorLogo" class="invalid-feedback">{{ $message }}</div>
                @enderror

                {{-- Visualización del logo actual y opción de remoción --}}
                @if ($marca->logo)
                    <div class="d-flex align-items-center gap-3 mt-3 p-2 bg-light rounded border">
                        <img src="{{ Storage::url($marca->logo) }}" alt="Logo actual de {{ $marca->nombre }}" height="40"
                            class="object-fit-contain">

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" value="1" id="quitar_logo"
                                name="quitar_logo">
                            <label class="form-check-label small" for="quitar_logo">Quitar el logo actual</label>
                        </div>
                    </div>
                @endif
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
        <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear marca' }}
            </button>

            <a href="{{ route('marcas.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
