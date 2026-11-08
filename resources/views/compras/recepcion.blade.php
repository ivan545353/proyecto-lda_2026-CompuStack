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
        <div class="card-body pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-body-secondary small d-inline-flex align-items-center gap-1">
                    <i class="bi bi-box-seam me-1" aria-hidden="true"></i> Ingresá las unidades contadas físicamente
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Los campos arrancan vacíos para registrar lo efectivamente contado. Si llegó todo el pedido, usá el botón para completarlo automáticamente."
                        aria-label="Ayuda sobre recepción">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </span>

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

            <div class="card-body pb-0">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="remito_numero" class="form-label">Número del remito del proveedor</label>
                        <input type="text" class="form-control @error('remito_numero') is-invalid @enderror"
                            id="remito_numero" name="remito_numero" value="{{ old('remito_numero') }}" maxlength="30"
                            autocomplete="off"
                            aria-describedby="ayudaRemitoNumero @error('remito_numero') errorRemitoNumero @enderror">
                        <div id="ayudaRemitoNumero" class="form-text">
                            Si vino con la mercadería. Ej: 0001-00012345.
                        </div>
                        @error('remito_numero')
                            <div id="errorRemitoNumero" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-4">
                        <label for="remito_fecha" class="form-label">Fecha del remito</label>
                        <input type="date" class="form-control @error('remito_fecha') is-invalid @enderror"
                            id="remito_fecha" name="remito_fecha" value="{{ old('remito_fecha') }}"
                            max="{{ now()->toDateString() }}"
                            aria-describedby="ayudaRemitoFecha @error('remito_fecha') errorRemitoFecha @enderror">
                        <div id="ayudaRemitoFecha" class="form-text">La que figura en el remito, no la de hoy.</div>
                        @error('remito_fecha')
                            <div id="errorRemitoFecha" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
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

                <div class="d-grid d-sm-flex justify-content-sm-end gap-2">
                    <a href="{{ route('compras.show', $orden) }}" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Registrar la recepción
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="alert alert-light border small text-body-secondary d-flex align-items-center gap-2">
        <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
        <div>
            Si el proveedor mandó de más, recibí lo pedido y cargá la diferencia como ajuste de inventario. Si no va a
            entregar el resto, podés usar <strong>«Cerrar con lo que llegó»</strong> en la orden.
        </div>
    </div>
@endsection
