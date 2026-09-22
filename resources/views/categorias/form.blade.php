@extends('layouts.app')

@php($esEdicion = $categoria->exists)
@php($volverA = route('categorias.index', array_filter(['parent_id' => $categoria->parent_id])))

@section('title', $esEdicion ? 'Editar categoría' : 'Nueva categoría')

@section('content')
    <a href="{{ $volverA }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$categoria->nombre}»" : 'Nueva categoría' }}</h1>
        <p class="text-body-secondary small mb-0">
            La dirección web se genera sola a partir del nombre.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate
        action="{{ $esEdicion ? route('categorias.update', $categoria) : route('categorias.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">Nombre <span class="text-danger"
                        aria-hidden="true">*</span></label>
                <input type="text" class="form-control @error('nombre') is-invalid @enderror" id="nombre"
                    name="nombre" value="{{ old('nombre', $categoria->nombre) }}" required maxlength="100" autofocus
                    aria-describedby="ayudaNombre @error('nombre') errorNombre @enderror">
                <div id="ayudaNombre" class="form-text">
                    Entre 2 y 100 caracteres. No puede repetirse dentro del mismo grupo.
                </div>
                @error('nombre')
                    <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="parent_id" class="form-label">Está dentro de</label>
                <select class="form-select @error('parent_id') is-invalid @enderror"
                    id="parent_id" name="parent_id" data-buscable="jerarquia"
                    aria-describedby="ayudaPadre @error('parent_id') errorPadre @enderror">
                    <option value="">Ninguna (es una categoría principal)</option>
                    <x-opciones-categoria :categorias="$padres"
                        :seleccionada="old('parent_id', $categoria->parent_id)" />
                </select>
                <div id="ayudaPadre" class="form-text">
                    @if ($esEdicion && $categoria->hijas_count > 0)
                        Tiene {{ $categoria->hijas_count }} subcategoría(s), que se mueven con
                        ella. Solo aparecen los lugares donde entran todas.
                    @else
                        Solo aparecen las categorías donde se puede agregar una subcategoría.
                    @endif
                </div>
                @error('parent_id')
                    <div id="errorPadre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="orden" class="form-label">Orden</label>
                <input type="number" class="form-control @error('orden') is-invalid @enderror" id="orden"
                    name="orden" value="{{ old('orden', $categoria->orden) }}" min="0" max="9999"
                    inputmode="numeric" aria-describedby="ayudaOrden @error('orden') errorOrden @enderror">
                <div id="ayudaOrden" class="form-text">Posición dentro de su grupo. Los números más bajos aparecen primero.
                </div>
                @error('orden')
                    <div id="errorOrden" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(old('activo', $categoria->activo ?? true))>
                    <label class="form-check-label" for="activo">Categoría activa</label>
                    <div class="form-text">
                        Una categoría inactiva no se ofrece al cargar productos ni recibe subcategorías nuevas.
                    </div>
                </div>
            </div>
        </div>

        {{-- En móvil, botones a lo ancho y el principal arriba. --}}
        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear categoría' }}
            </button>
            <a href="{{ $volverA }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
