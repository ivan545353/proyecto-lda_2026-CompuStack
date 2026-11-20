@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id; la marca la trae pantallas/marcas.js. --}}
@php($esEdicion = isset($marcaId))

@section('title', $esEdicion ? 'Editar marca' : 'Nueva marca')

@section('content')
    <a href="{{ route('marcas.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-marca>{{ $esEdicion ? 'Editar marca' : 'Nueva marca' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" enctype="multipart/form-data" novalidate data-formulario-marca
        data-marca="{{ $marcaId ?? '' }}" data-volver="{{ route('marcas.index') }}"
        class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3">
            {{-- Nombre de la marca --}}
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="100"
                    autofocus aria-describedby="ayudaNombre">

                <div id="ayudaNombre" class="form-text">Entre 2 y 100 caracteres. No puede repetirse.</div>
            </div>

            {{-- Logotipo opcional (con soporte para reemplazo y eliminación) --}}
            <div class="col-12 col-md-6">
                <span class="form-label d-block">Logo</span>

                <x-gestor-imagenes :max="1" :max-kb="512" campo="logo" campo-quitar="quitar_logo"
                    etiqueta-agregar="Agregar logo" descripcion="la marca" :diferido="$esEdicion" />
            </div>

            {{-- Estado de disponibilidad en catálogo --}}
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(!$esEdicion)>
                    <label class="form-check-label" for="activo">Marca activa</label>
                    <div class="form-text">Una marca inactiva no se ofrece al dar de alta nuevos productos.</div>
                </div>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('marcas.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear marca' }}
            </button>
        </div>
    </form>
@endsection
