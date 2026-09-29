@extends('layouts.app')

@section('title', 'Cambiar rol')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Cambiar el rol de {{ $usuario->nombre_completo }}</h1>
        <p class="text-body-secondary small mb-0">
            El rol decide qué puede hacer la persona dentro del sistema.
        </p>
    </div>

    @include('partials.errores')

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <form method="POST" action="{{ route('usuarios.rol.update', $usuario) }}" novalidate
                class="card card-body border-0 shadow-sm">
                @csrf
                @method('PATCH')

                <div class="mb-3">
                    <span class="form-label d-block">Rol actual</span>
                    <p class="form-control-plaintext mb-0 fw-semibold">{{ $usuario->rol->nombre }}</p>
                    @if ($usuario->rol->descripcion)
                        <div class="form-text">{{ $usuario->rol->descripcion }}</div>
                    @endif
                </div>

                <div class="mb-3">
                    <label for="rol_id" class="form-label">
                        Rol nuevo <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <select class="form-select @error('rol_id') is-invalid @enderror" id="rol_id" name="rol_id"
                        aria-describedby="ayudaRol @error('rol_id') errorRol @enderror">
                        <option value="">Elegí el rol nuevo</option>
                        @foreach ($roles as $rol)
                            <option value="{{ $rol->id }}" @disabled($rol->is($usuario->rol))
                                @selected((int) old('rol_id') === $rol->id)>
                                {{ $rol->nombre }}{{ $rol->is($usuario->rol) ? ' (actual)' : '' }}
                            </option>
                        @endforeach
                    </select>

                    <div id="ayudaRol" class="form-text">
                        Sólo aparecen los roles equivalentes al actual. Un rol de la tienda pide datos de
                        facturación y uno del personal pide datos laborales, así que no se intercambian.
                    </div>

                    @error('rol_id')
                        <div id="errorRol" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="alert alert-warning d-flex gap-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>
                    <div>
                        El cambio rige de inmediato: en su próxima acción la persona va a poder hacer
                        exactamente lo que permita el rol nuevo, ni más ni menos.
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-acento">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Cambiar el rol
                    </button>

                    <a href="{{ route('usuarios.edit', $usuario) }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>

        {{-- Qué puede hacer hoy, para que el cambio se haga con el dato a la vista
             y no de memoria --}}
        <div class="col-12 col-lg-5">
            <div class="card card-body border-0 shadow-sm">
                <h2 class="h6 mb-3">Hoy puede</h2>

                @forelse ($usuario->rol->permisos->sortBy('clave') as $permiso)
                    <div class="small text-body-secondary">{{ $permiso->descripcion }}</div>
                @empty
                    <p class="small text-body-secondary mb-0">
                        Este rol no tiene permisos sobre el sistema de gestión.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
@endsection