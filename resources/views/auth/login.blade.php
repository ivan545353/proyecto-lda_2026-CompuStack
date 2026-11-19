@extends('layouts.auth')

@section('title', 'Ingresar')

@section('content')
    {{-- Lo envía el módulo central a POST /api/v1/sesion (tramo C, D-30): no tiene action ni CSRF. --}}
    <form id="formularioIngreso" data-destino="{{ route('inicio') }}" class="form-authentication"
        autocomplete="off" novalidate>

        <div class="card card-body m-3 gap-2 shadow-sm border-0">

            <div id="avisosApi" aria-live="polite"></div>

            <div class="d-flex justify-content-center mb-4">
                <img src="{{ asset('assets/imagotipo.svg') }}" alt="{{ config('app.name') }}" height="48">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Correo</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}"
                    class="form-control @error('email') is-invalid @enderror" required autofocus autocomplete="username">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <input type="password" id="password" name="password"
                        class="form-control @error('password') is-invalid @enderror" required
                        autocomplete="current-password">
                    <button class="btn btn-outline-secondary" type="button" id="verContrasena"
                        aria-label="Mostrar u ocultar la contraseña">
                        <i class="bi bi-eye"></i>
                    </button>
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <button type="submit" class="btn btn-acento">Ingresar</button>

            <p class="text-center text-body-secondary small mt-3 mb-0">
                Las cuentas las crea un administrador.
            </p>
        </div>
    </form>
@endsection
