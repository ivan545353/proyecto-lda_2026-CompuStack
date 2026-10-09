@extends('layouts.app')

@use('App\Support\Importe')

@php
    $pendientes = $orden->lineas->sum(fn($linea) => $linea->cantidadPendiente());
@endphp

@section('title', "Recibir mercadería — {$orden->numeroFormateado()}")

@section('content')
    <a href="{{ route('compras.show', $orden) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la orden
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Recibir mercadería</h1>
        <p class="text-body-secondary small mb-0">
            Orden <span class="fw-semibold">{{ $orden->numeroFormateado() }}</span> a
            <span class="fw-semibold">{{ $orden->proveedor->razon_social }}</span>.
            Cargá <strong>cuántas unidades llegaron de verdad</strong>: con eso se ingresa el stock, se
            recalcula el costo promedio de cada producto y queda al día el último costo de este proveedor.
        </p>
    </div>

    @include('partials.errores')

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body pb-0">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <p class="text-body-secondary small mb-3">
                    Los campos arrancan vacíos a propósito: lo que se carga acá mueve stock, así que
                    conviene escribir lo que se contó y no confirmar lo que se esperaba. Si llegó todo,
                    el botón lo completa de una.
                </p>

                <button type="button" class="btn btn-sm btn-outline-dark" data-recibir-todo>
                    <i class="bi bi-check2-all" aria-hidden="true"></i> Llegó todo lo pendiente
                </button>
            </div>
        </div>

        <form method="POST" novalidate action="{{ route('compras.recibir', $orden) }}" data-recepcion>
            @csrf

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">
                        Productos de la orden {{ $orden->numeroFormateado() }}, con cuántas unidades llegaron
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Pedidas</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Ya recibidas</th>
                            <th scope="col" class="text-end">Pendientes</th>
                            <th scope="col" class="text-end">Llegaron ahora</th>
                            <th scope="col" class="text-end">Quedarían</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orden->lineas as $linea)
                            @php($pendiente = $linea->cantidadPendiente())
                            <tr data-linea data-pendiente="{{ $pendiente }}">
                                <td>
                                    <span class="fw-semibold">{{ $linea->producto->nombre }}</span>
                                    <div class="small text-body-secondary">
                                        {{ $linea->producto->codigo }}
                                        @if ($linea->codigo_proveedor)
                                            · para el proveedor: {{ $linea->codigo_proveedor }}
                                        @endif
                                    </div>
                                </td>

                                <td class="text-end">{{ $linea->cantidad_pedida }}</td>
                                <td class="text-end d-none d-md-table-cell">{{ $linea->cantidad_recibida }}</td>
                                <td class="text-end">{{ $pendiente }}</td>

                                <td class="text-end" style="width: 9rem;">
                                    <label class="visually-hidden" for="cantidad_{{ $linea->id }}">
                                        Unidades de {{ $linea->producto->nombre }} que llegaron
                                    </label>

                                    @if ($pendiente === 0)
                                        {{-- Una línea completa no se puede recibir más, así que no se
                                             ofrece el campo. Deshabilitado no viaja en el POST, y el
                                             servicio lo rechazaría igual. --}}
                                        <span class="badge text-bg-success">Completa</span>
                                        <input type="hidden" disabled>
                                    @else
                                        <input type="number"
                                            class="form-control form-control-sm text-end @error("cantidades.{$linea->id}") is-invalid @enderror"
                                            id="cantidad_{{ $linea->id }}" name="cantidades[{{ $linea->id }}]"
                                            value="{{ old("cantidades.{$linea->id}") }}" min="0"
                                            max="{{ $pendiente }}" step="1" inputmode="numeric" data-recibidas>

                                        @error("cantidades.{$linea->id}")
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    @endif
                                </td>

                                <td class="text-end" data-quedan>{{ $pendiente }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-top pt-3 mb-3">
                    {{-- Igual que el aviso de la pantalla de ajuste: ver la consecuencia
                         antes de guardar. Un 30 escrito en lugar de un 3 se delata solo. --}}
                    <p class="small mb-0" data-total-recibido aria-live="polite"></p>

                    <p class="text-body-secondary small mb-0">
                        Pendientes en total: <span class="fw-semibold">{{ $pendientes }}</span>
                    </p>
                </div>

                <div class="d-grid d-sm-flex gap-2">
                    <button type="submit" class="btn btn-acento">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Registrar la recepción
                    </button>

                    <a href="{{ route('compras.show', $orden) }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>
        </form>
    </div>

    <p class="text-body-secondary small">
        Si el proveedor mandó <strong>más</strong> de lo pedido, recibí lo pedido y cargá la diferencia
        como ajuste de inventario: así la orden sigue diciendo lo que se acordó. Si no va a entregar el
        resto, en la ficha de la orden está «Cerrar con lo que llegó».
    </p>
@endsection
