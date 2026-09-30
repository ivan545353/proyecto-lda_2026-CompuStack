@extends('layouts.auth')

@section('title', 'Configurar mi contraseña')

@section('content')
    <form method="POST" action="{{ route('password.restablecer.enviar') }}" novalidate class="form-authentication">
        @csrf

        {{-- Vienen del enlace, no los escribe nadie. El correo además le dice al
             gestor de contraseñas a qué cuenta corresponde lo que guarda. --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ old('email', $email) }}" autocomplete="username">

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

            @include('partials.errores')

            <div class="mb-3">
                <label for="password" class="form-label">
                    Contraseña nueva <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="password" id="password" name="password" autofocus autocomplete="new-password"
                    class="form-control @error('password') is-invalid @enderror"
                    aria-describedby="ayudaPassword @error('password') errorPassword @enderror">

                <div id="ayudaPassword" class="form-text">
                    Al menos 8 caracteres, con letras y números.
                </div>

                @error('password')
                    <div id="errorPassword" class="invalid-feedback">{{ $message }}</div>
                @enderror
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
@endsection
