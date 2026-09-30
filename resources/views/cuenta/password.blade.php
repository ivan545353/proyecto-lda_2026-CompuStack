@extends('layouts.app')

@section('title', 'Cambiar mi contraseña')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <div class="mb-4">
                <h1 class="h3 mb-1">Cambiar mi contraseña</h1>
                <p class="text-body-secondary small mb-0">
                    Estás cambiando la contraseña de tu propia cuenta
                    ({{ auth()->user()->email }}).
                </p>
            </div>

            @include('partials.errores')

            <form method="POST" action="{{ route('cuenta.password.update') }}" novalidate
                class="card card-body border-0 shadow-sm">
                @csrf
                @method('PUT')

                {{-- Los gestores de contraseñas necesitan saber de quién es la
                     cuenta para ofrecer y guardar la credencial correcta. --}}
                <input type="hidden" name="email" value="{{ auth()->user()->email }}" autocomplete="username">

                <div class="mb-3">
                    <label for="password_actual" class="form-label">
                        Contraseña actual <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password_actual" name="password_actual"
                        class="form-control @error('password_actual') is-invalid @enderror" autocomplete="current-password"
                        autofocus aria-describedby="ayudaActual @error('password_actual') errorActual @enderror">

                    <div id="ayudaActual" class="form-text">
                        Te la pedimos para confirmar que sos vos, aunque ya hayas iniciado sesión.
                    </div>

                    @error('password_actual')
                        <div id="errorActual" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <hr class="my-4">

                <div class="mb-3">
                    <label for="password" class="form-label">
                        Contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password" name="password"
                        class="form-control @error('password') is-invalid @enderror" autocomplete="new-password"
                        aria-describedby="ayudaNueva @error('password') errorNueva @enderror">

                    <div id="ayudaNueva" class="form-text">
                        Al menos 8 caracteres, con letras y números. Tiene que ser distinta de la actual.
                    </div>

                    @error('password')
                        <div id="errorNueva" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">
                        Repetir la contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                    </label>

                    <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                        autocomplete="new-password">
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-acento">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Cambiar mi contraseña
                    </button>

                    <a href="{{ route('panel') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>

            <p class="text-body-secondary small mt-3 mb-0">
                Si no te acordás de tu contraseña actual, pedile a un administrador que te la restablezca.
            </p>
        </div>
    </div>
@endsection
