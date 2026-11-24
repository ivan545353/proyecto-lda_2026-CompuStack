@extends('layouts.auth')

@section('title', 'Configurar mi contraseña')

{{-- Lo envía pantallas/sesion.js a POST /api/v1/contrasena/restablecer (tramo I): no tiene action ni CSRF.
     El token y el correo vienen del enlace, no de la base: no hay dato de negocio en esta página. --}}
@section('content')
    <form novalidate class="form-authentication" data-formulario-restablecer data-ingreso="{{ route('login') }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}" autocomplete="username">

        <div class="card card-body m-3 gap-2 shadow-sm border-0">
            <div class="d-flex justify-content-center mb-4">
                <img src="{{ asset('assets/imagotipo.svg') }}" alt="{{ config('app.name') }}" height="48">
            </div>

            <h1 class="h5 text-center mb-1">Configurá tu contraseña</h1>

            <p class="text-body-secondary small text-center mb-4">
                @if ($email)
                    Para la cuenta <span class="fw-semibold">{{ $email }}</span>.
                @endif
                Este enlace sirve una sola vez.
            </p>

            {{-- Un enlace vencido o ya usado es un error del correo, que está oculto: va acá arriba. --}}
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="mb-3">
                <label for="password" class="form-label">
                    Contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="password" id="password" name="password" autofocus autocomplete="new-password"
                    class="form-control" aria-describedby="ayudaPassword">

                <div id="ayudaPassword" class="form-text">
                    Al menos 8 caracteres, con letras y números.
                </div>
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">
                    Repetir la contraseña <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
                    autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-acento">Guardar y continuar</button>

            <p class="text-center text-body-secondary small mt-3 mb-0">
                Si el enlace ya venció, pedile otro a un administrador.
            </p>
        </div>
    </form>

    {{-- La cuenta es de la tienda: el ingreso de la gestión la rechazaría, así que se le dice dónde sí (D-26).
         Lo muestra el JS cuando la API contesta `ambito: tienda`. --}}
    <div class="form-authentication text-center" data-cuenta-tienda-lista hidden>
        <div class="card card-body m-3 shadow-sm border-0">
            <i class="bi bi-phone fs-1 d-block mb-2" aria-hidden="true"></i>
            <h1 class="h4 mb-3">Tu contraseña quedó configurada</h1>
            <p class="mb-0">
                Ya podés entrar desde la app de la tienda con tu correo y la contraseña que elegiste.
                Esta página es del sistema interno del local: no hace falta que vuelvas a ella.
            </p>
        </div>
    </div>
@endsection
