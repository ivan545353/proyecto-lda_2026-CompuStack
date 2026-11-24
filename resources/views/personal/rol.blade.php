@extends('layouts.app')

{{-- Shell (regla 37): sólo llega el id. La persona, su rol actual con lo que hoy puede y los roles que se le
     pueden asignar vienen en su ficha, que pide pantallas/personal.js a la API. --}}
@section('title', 'Cambiar rol')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Cambiar el rol de <span data-campo="nombre">esta persona</span></h1>
        <p class="text-body-secondary small mb-0">
            El rol decide qué puede hacer el usuario dentro del sistema.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <form method="POST" novalidate data-formulario-rol data-usuario="{{ $usuarioId }}"
                data-volver="{{ route('personal.edit', $usuarioId) }}" class="card card-body border-0 shadow-sm">
                <p class="alert alert-danger" data-errores-formulario hidden></p>

                <div class="mb-3">
                    <span class="form-label d-block">Rol actual</span>
                    <p class="form-control-plaintext mb-0 fw-semibold" data-campo="rol-actual"></p>
                    <div class="form-text" data-campo="rol-descripcion" hidden></div>
                </div>

                <div class="mb-3">
                    <label for="rol_id" class="form-label">
                        Rol nuevo <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    {{-- Las opciones son los roles asignables de la ficha: las pone el JS. --}}
                    <select class="form-select" id="rol_id" name="rol_id" aria-describedby="ayudaRol">
                        <option value="">Elegí el rol nuevo</option>
                    </select>
                    <div id="ayudaRol" class="form-text">
                        Sólo aparecen los roles del personal. Cambiar de ámbito no es un cambio de rol:
                        pide otros datos y otra ficha.
                    </div>
                </div>

                <div class="alert alert-warning d-flex gap-3 mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>
                    <div>
                        El cambio rige de inmediato: en su próxima acción el usuario va a poder hacer
                        exactamente lo que permita el rol nuevo, ni más ni menos.
                    </div>
                </div>

                <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                    <a href="{{ route('personal.edit', $usuarioId) }}" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Cambiar rol
                    </button>
                </div>
            </form>
        </div>

        {{-- Qué puede hacer hoy, para que el cambio se haga con el dato a la vista y no de memoria --}}
        <div class="col-12 col-lg-5">
            <div class="card card-body border-0 shadow-sm">
                <h2 class="h6 mb-3">Hoy puede</h2>
                <div data-permisos-actuales data-vacio="Este rol no tiene permisos sobre el sistema de gestión."></div>
            </div>
        </div>
    </div>
@endsection
