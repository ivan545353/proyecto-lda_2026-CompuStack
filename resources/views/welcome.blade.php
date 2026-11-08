@extends('layouts.app')

@section('title', 'Bienvenido')

@section('content')
    <div class="d-flex align-items-center justify-content-center" style="min-height: 70vh;">
        <div class="text-center p-5 bg-white rounded shadow-sm">
            <h1 class="text-primary fw-bold display-4">{{ config('app.name') }}</h1>
            <p class="text-body-secondary lead">¡Bienvenido a tu nueva aplicación con Bootstrap!</p>
            <hr class="my-4">
            <a href="#" class="btn btn-primary px-4">Comenzar</a>
        </div>
    </div>
@endsection
