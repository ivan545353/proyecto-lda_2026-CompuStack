@extends('layouts.app')

@section('title', 'Panel')

@section('content')
    <h1 class="h3 mb-4">Panel</h1>

    <div class="card">
        <div class="card-body">
            <p class="mb-1">
                Sesión iniciada como <strong>{{ auth()->user()->nombre_completo }}</strong>.
            </p>
            <p class="text-muted small mb-0">
                Rol: {{ auth()->user()->rol->nombre }} ·
                Permisos activos: {{ auth()->user()->clavesDePermiso()->count() }}
            </p>
        </div>
    </div>
@endsection
