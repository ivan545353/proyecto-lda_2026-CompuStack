@extends('layouts.auth')

@section('title', 'Tu contraseña quedó configurada')

{{-- La cuenta es de la tienda: el inicio de sesión de la gestión la rechazaría (D-26). --}}
@section('content')
    <div class="form-authentication text-center" data-cuenta-tienda-lista>
        <i class="bi bi-phone fs-1 d-block mb-2" aria-hidden="true"></i>
        <h1 class="h4 mb-3">Tu contraseña quedó configurada</h1>
        <p class="mb-0">
            Ya podés entrar desde la app de la tienda con tu correo y la contraseña que elegiste.
            Esta página es del sistema interno del local: no hace falta que vuelvas a ella.
        </p>
    </div>
@endsection
