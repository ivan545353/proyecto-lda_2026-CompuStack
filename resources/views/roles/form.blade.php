@extends('layouts.app')

@section('title', $rol->exists ? 'Editar rol' : 'Nuevo rol')

@section('content')
    @php
        // Si venimos de un intento fallido, mandan los checkboxes que el usuario
        // dejó marcados, aunque sean ninguno. Usar el valor por defecto acá
        // desharía lo que acaba de hacer.
        $marcados = $errors->any() ? array_map('intval', old('permisos', [])) : $permisosActuales;
    @endphp
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">{{ $rol->exists ? "Editar «{$rol->nombre}»" : 'Nuevo rol' }}</h1>
        <a href="{{ route('roles.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    @include('partials.errores')


    <form method="POST" action="{{ $rol->exists ? route('roles.update', $rol) : route('roles.store') }}" novalidate>
        @csrf
        @if ($rol->exists)
            @method('PUT')
        @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                @if ($rol->es_sistema)
                    <div class="alert alert-secondary small">
                        <i class="bi bi-lock"></i>
                        Es un rol del sistema: el nombre y el ámbito no se modifican.
                        Sí podés ajustar su descripción y sus permisos.
                    </div>
                @endif

                <p class="text-body-secondary small">
                    Los campos marcados con <span class="text-danger" aria-hidden="true">*</span>
                    son obligatorios.
                </p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="nombre" class="form-label">Nombre<span class="text-danger"
                                aria-hidden="true">*</span></label>
                        <input type="text" id="nombre" name="nombre" value="{{ old('nombre', $rol->nombre) }}"
                            class="form-control @error('nombre') is-invalid @enderror" @disabled($rol->es_sistema)>
                        @error('nombre')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label for="ambito" class="form-label">Ámbito<span class="text-danger"
                                aria-hidden="true">*</span></label>
                        <select id="ambito" name="ambito" class="form-select @error('ambito') is-invalid @enderror"
                            @disabled($rol->es_sistema)>
                            <option value="gestion" @selected(old('ambito', $rol->ambito) === 'gestion')>
                                Gestión
                            </option>
                            <option value="tienda" @selected(old('ambito', $rol->ambito) === 'tienda')>
                                Tienda
                            </option>
                        </select>
                        @error('ambito')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Decide si accede al sistema de gestión o a la tienda.</div>
                    </div>

                    <div class="col-md-5">
                        <label for="descripcion" class="form-label">Descripción</label>
                        <input type="text" id="descripcion" name="descripcion"
                            value="{{ old('descripcion', $rol->descripcion) }}"
                            class="form-control @error('descripcion') is-invalid @enderror">
                        @error('descripcion')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm @error('permisos') border border-danger @enderror">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Permisos</span>
                <span class="text-body-secondary small" id="contadorPermisos" aria-live="polite"></span>
            </div>

            <div class="card-body">

                <p class="text-body-secondary small mb-3">
                    Marcar cualquier acción habilita automáticamente la de ver ese módulo.
                    Quitar la de ver quita el módulo entero.
                </p>

                <div class="row g-4">
                    @foreach ($permisos as $modulo => $delModulo)
                        <div class="col-md-6 col-lg-4">
                            <div class="border rounded p-3 h-100">
                                <div class="form-check border-bottom pb-2 mb-2">
                                    <input class="form-check-input js-modulo" type="checkbox"
                                        id="modulo-{{ $modulo }}" data-modulo="{{ $modulo }}"
                                        aria-describedby="ayuda-{{ $modulo }}">
                                    <label class="form-check-label fw-semibold text-capitalize"
                                        for="modulo-{{ $modulo }}">
                                        {{ $modulo }}
                                    </label>
                                    <span id="ayuda-{{ $modulo }}" class="visually-hidden">
                                        Marca o desmarca todos los permisos de {{ $modulo }}
                                    </span>
                                </div>

                                @foreach ($delModulo as $permiso)
                                    <div class="form-check">
                                        <input class="form-check-input js-permiso" type="checkbox" name="permisos[]"
                                            value="{{ $permiso->id }}" id="permiso-{{ $permiso->id }}"
                                            data-modulo="{{ $modulo }}"
                                            data-accion="{{ \Illuminate\Support\Str::after($permiso->clave, '.') }}"
                                            @checked(in_array($permiso->id, $marcados))>
                                        <label class="form-check-label small" for="permiso-{{ $permiso->id }}">
                                            {{ $permiso->descripcion }}
                                            <span class="clave-permiso ms-1">{{ $permiso->clave }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-acento">
                    <i class="bi bi-check-lg"></i> Guardar
                </button>
            </div>
        </div>
    </form>
@endsection
