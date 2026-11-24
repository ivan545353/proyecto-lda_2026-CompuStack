@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id. El rol, sus permisos y el catálogo de permisos los trae
     pantallas/roles.js por la API, y arma ahí las casillas por módulo. --}}
@php($esEdicion = isset($rolId))

@section('title', $esEdicion ? 'Editar rol' : 'Nuevo rol')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-4">
        <h1 class="h3 mb-0" data-titulo-rol>{{ $esEdicion ? 'Editar rol' : 'Nuevo rol' }}</h1>
        <a href="{{ route('roles.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central; el de los permisos, arriba. --}}
    <form method="POST" novalidate data-formulario-de-rol data-rol="{{ $rolId ?? '' }}"
        data-volver="{{ route('roles.index') }}">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="alert alert-secondary small" data-rol-sistema hidden>
                    <i class="bi bi-lock"></i>
                    Es un rol del sistema: el nombre y el ámbito no se modifican.
                    Sí podés ajustar su descripción y sus permisos.
                </div>

                <p class="text-body-secondary small">
                    Los campos marcados con <span class="text-danger" aria-hidden="true">*</span>
                    son obligatorios.
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="nombre" class="form-label">Nombre<span class="text-danger"
                                aria-hidden="true">*</span></label>
                        <input type="text" id="nombre" name="nombre" maxlength="50" class="form-control">
                    </div>

                    <div class="col-md-3">
                        <label for="ambito" class="form-label">Ámbito<span class="text-danger"
                                aria-hidden="true">*</span></label>
                        <select id="ambito" name="ambito" class="form-select" aria-describedby="ayudaAmbito">
                            <option value="gestion" selected>Gestión</option>
                            <option value="tienda">Tienda</option>
                        </select>
                        <div id="ayudaAmbito" class="form-text">Define el acceso: panel de gestión o tienda online.</div>
                    </div>

                    <div class="col-md-5">
                        <label for="descripcion" class="form-label">Descripción</label>
                        <input type="text" id="descripcion" name="descripcion" maxlength="150" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold">Permisos</span>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Marcar cualquier acción habilita la de ver ese módulo. Quitar la de ver desmarca el módulo entero."
                        aria-label="Regla de selección de permisos">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>
                <span class="text-body-secondary small" data-contador-permisos aria-live="polite"></span>
            </div>

            <div class="card-body">
                {{-- Un módulo por tarjeta, con su casilla de «todos»; las arma el JS con el catálogo de la API. --}}
                <div class="row g-4" data-permisos>
                    <p class="text-body-secondary small mb-0">Cargando los permisos…</p>
                </div>
            </div>

            <div class="card-footer bg-white d-grid d-sm-flex justify-content-sm-end gap-2">
                <a href="{{ route('roles.index') }}" class="btn btn-outline-secondary order-sm-first">Cancelar</a>
                <button type="submit" class="btn btn-acento px-4">
                    <i class="bi bi-check-lg"></i> Guardar
                </button>
            </div>
        </div>
    </form>

    <template data-modulo-permisos>
        <div class="col-md-6 col-lg-4">
            <div class="border rounded p-3 h-100">
                <div class="form-check border-bottom pb-2 mb-2">
                    <input class="form-check-input js-modulo" type="checkbox">
                    <label class="form-check-label fw-semibold text-capitalize" data-campo="modulo"></label>
                    <span class="visually-hidden" data-campo="ayuda-modulo"></span>
                </div>
                <div data-lista-permisos></div>
            </div>
        </div>
    </template>

    <template data-permiso>
        <div class="form-check">
            <input class="form-check-input js-permiso" type="checkbox" name="permisos">
            <label class="form-check-label small">
                <span data-campo="descripcion"></span>
                <span class="clave-permiso ms-1" data-campo="clave"></span>
            </label>
        </div>
    </template>
@endsection
