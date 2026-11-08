<div>
    <!-- He who is contented is rich. - Laozi -->
</div>
@extends('layouts.app')

@php
    $pendientes = $venta->lineas->sum(fn($linea) => $linea->cantidadPendienteDeEntregar());
@endphp

@section('title', "Entrega — {$venta->numeroFormateado()}")

@section('content')
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Entregar una parte</h1>
        <p class="text-body-secondary small mb-0">
            Venta <span class="fw-semibold">{{ $venta->numeroFormateado() }}</span> a
            <span class="fw-semibold">{{ $venta->clienteTexto() }}</span>.
            Cargá <strong>cuántas unidades salen ahora</strong> de cada renglón; lo demás queda pendiente
            y se entrega después.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ route('ventas.entregar', $venta) }}">
        @csrf

        <div class="card border-0 shadow-sm mb-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">
                        Productos de la venta {{ $venta->numeroFormateado() }}, con cuántas unidades salen ahora
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Vendidas</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Ya entregadas</th>
                            <th scope="col" class="text-end">Pendientes</th>
                            <th scope="col" class="text-end">Salen ahora</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($venta->lineas as $linea)
                            @php($pendiente = $linea->cantidadPendienteDeEntregar())
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $linea->descripcion }}</span>
                                    <div class="small text-body-secondary">{{ $linea->producto->codigo }}</div>
                                </td>

                                <td class="text-end">{{ $linea->cantidad }}</td>
                                <td class="text-end d-none d-md-table-cell">{{ $linea->cantidad - $pendiente }}</td>
                                <td class="text-end">{{ $pendiente }}</td>

                                <td class="text-end" style="width: 9rem;">
                                    @if ($pendiente === 0)
                                        <span class="badge text-bg-success">Entregada</span>
                                    @else
                                        <label class="visually-hidden" for="cantidad_{{ $linea->id }}">
                                            Unidades de {{ $linea->descripcion }} que salen ahora
                                        </label>
                                        <input type="number"
                                            class="form-control form-control-sm text-end @error("cantidades.{$linea->id}") is-invalid @enderror"
                                            id="cantidad_{{ $linea->id }}" name="cantidades[{{ $linea->id }}]"
                                            value="{{ old("cantidades.{$linea->id}") }}" min="0"
                                            max="{{ $pendiente }}" step="1" inputmode="numeric"
                                            @error("cantidades.{$linea->id}") aria-describedby="error_cantidad_{{ $linea->id }}" @enderror>

                                        @error("cantidades.{$linea->id}")
                                            <div id="error_cantidad_{{ $linea->id }}" class="invalid-feedback d-block">
                                                {{ $message }}</div>
                                        @enderror
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-body border-top">
                <p class="text-body-secondary small mb-0">
                    Pendientes de entregar en total: <span class="fw-semibold">{{ $pendientes }}</span> unidad(es).
                    Si la entrega completa la venta, queda entregada; si no, entregada en parte.
                </p>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Registrar la entrega
            </button>
        </div>
    </form>
@endsection
