@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id; la categoría, sus padres posibles y lo que
     tiene adentro los trae pantallas/categorias.js. En el alta, el grupo de la URL viene elegido. --}}
@php($esEdicion = isset($categoriaId))
@php($padreInicial = $esEdicion ? null : (ctype_digit((string) request('parent_id')) ? (int) request('parent_id') : null))
@php($volverA = route('categorias.index', array_filter(['parent_id' => $padreInicial])))

@section('title', $esEdicion ? 'Editar categoría' : 'Nueva categoría')

@section('content')
    <a href="{{ $volverA }}" class="btn btn-link link-dark px-0 mb-3" data-volver-al-grupo>
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-categoria>{{ $esEdicion ? 'Editar categoría' : 'Nueva categoría' }}</h1>
        <p class="text-body-secondary small mb-0">
            La dirección web se genera sola a partir del nombre.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" novalidate data-formulario-categoria data-categoria="{{ $categoriaId ?? '' }}"
        data-padre-inicial="{{ $padreInicial }}" data-volver="{{ route('categorias.index') }}"
        class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">Nombre <span class="text-danger"
                        aria-hidden="true">*</span></label>
                <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="100"
                    autofocus aria-describedby="ayudaNombre">
                <div id="ayudaNombre" class="form-text">
                    Entre 2 y 100 caracteres. No puede repetirse dentro del mismo grupo.
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="parent_id" class="form-label">Está dentro de</label>
                <select class="form-select" id="parent_id" name="parent_id" data-buscable="jerarquia"
                    data-buscable-diferido aria-describedby="ayudaPadre">
                    <option value="">Ninguna (es una categoría principal)</option>
                </select>
                <div id="ayudaPadre" class="form-text">
                    Solo aparecen las categorías donde se puede agregar una subcategoría.
                </div>
            </div>

            <div class="col-12 col-sm-6 col-md-3">
                <label for="orden" class="form-label">Orden</label>
                <input type="number" class="form-control" id="orden" name="orden" value="0" min="0" max="9999"
                    inputmode="numeric" aria-describedby="ayudaOrden">
                <div id="ayudaOrden" class="form-text">Posición dentro de su grupo. Los números más bajos aparecen primero.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input interruptor-activo" type="checkbox" value="1" id="activo"
                        name="activo" @checked(!$esEdicion)>
                    <label class="form-check-label" for="activo">Categoría activa</label>
                    <div class="form-text">
                        Una categoría inactiva no se ofrece al cargar productos ni recibe subcategorías nuevas.
                    </div>
                </div>

                {{-- Sólo en la edición, y sólo si tiene contenido activo: lo decide el JS con la ficha. --}}
                @if ($esEdicion)
                    <div class="al-desactivar alert alert-warning mt-3 mb-0" data-aviso-contenido hidden>
                        <p class="mb-2" data-contenido-texto></p>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" value="1" id="desactivar_contenido"
                                name="desactivar_contenido">
                            <label class="form-check-label" for="desactivar_contenido">
                                Desactivar también todo su contenido
                            </label>
                            <div class="form-text">
                                Para cuando se discontinúa la línea entera. No se puede deshacer en un solo paso:
                                reactivar la categoría después no vuelve a activar lo que se desactivó acá.
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ $volverA }}" class="btn btn-outline-secondary" data-volver-al-grupo>Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear categoría' }}
            </button>
        </div>
    </form>
@endsection
