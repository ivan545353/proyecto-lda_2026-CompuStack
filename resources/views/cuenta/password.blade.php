@extends('layouts.app')

@section('title', 'Cambiar mi contraseña')

{{-- Shell (regla 37): envía a PUT /api/v1/yo/contrasena por pantallas/sesion.js. El correo de la cuenta lo
     trae el JS de /yo; el servidor rota la credencial y el módulo central guarda la nueva (HL-132). --}}
@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="mb-4">
                <h1 class="h3 mb-1">Cambiar mi contraseña</h1>
                <p class="text-body-secondary small mb-0">
                    Estás cambiando la contraseña de tu propia cuenta<span data-campo="cuenta-email"></span>.
                </p>
            </div>

            <form method="POST" novalidate data-formulario-contrasena data-volver="{{ route('panel') }}"
                class="card card-body border-0 shadow-sm">
                <p class="alert alert-danger" data-errores-formulario hidden></p>

                {{-- Los gestores de contraseñas necesitan saber de quién es la cuenta para ofrecer y guardar
                     la credencial correcta. No viaja a la API. --}}
                <input type="hidden" name="email" value="" autocomplete="username">

                <div class="mb-3">
                    <label for="password_actual" class="form-label">
                        Contraseña actual <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password_actual" name="password_actual" class="form-control"
                        autocomplete="current-password" autofocus aria-describedby="ayudaActual">

                    <div id="ayudaActual" class="form-text">
                        Te la pedimos para confirmar que sos vos, aunque ya hayas iniciado sesión.
                    </div>
                </div>

                <hr class="my-4">

                <div class="mb-3">
                    <label for="password" class="form-label">
                        Contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password" name="password" class="form-control" autocomplete="new-password"
                        aria-describedby="ayudaNueva">

                    <div id="ayudaNueva" class="form-text">
                        Al menos 8 caracteres, con letras y números. Tiene que ser distinta de la actual.
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">
                        Repetir la contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                        autocomplete="new-password">
                </div>

                <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                    <a href="{{ route('panel') }}" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Cambiar contraseña
                    </button>
                </div>
            </form>

            <p class="text-body-secondary small mt-3 mb-0">
                Si no te acordás de tu contraseña actual, pedile a un administrador que te la restablezca.
            </p>
        </div>
    </div>
@endsection
