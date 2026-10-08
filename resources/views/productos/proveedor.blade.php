@extends('layouts.app')

@section('title', 'Editar proveedor del producto')

@section('content')
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <a href="{{ route('producto-proveedores.index', $producto) }}" class="btn btn-link link-dark px-0 mb-3">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a los proveedores del producto
            </a>

            <div class="mb-4">
                <h1 class="h3 mb-1">Editar el proveedor</h1>
                <p class="text-body-secondary small mb-0">
                    De <span class="fw-semibold">{{ $producto->nombre }}</span>, código {{ $producto->codigo }}.
                </p>
            </div>

            @include('partials.errores')

            <div class="card card-body border-0 shadow-sm">
                @include('productos._proveedor', [
                    'producto' => $producto,
                    'vinculo' => $vinculo,
                    'disponibles' => collect(),
                    'esElPrimero' => false,
                ])
            </div>
        </div>
    </div>
@endsection
