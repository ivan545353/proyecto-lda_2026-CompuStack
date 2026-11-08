@extends('layouts.app')

@use('App\Models\Pago')
@use('App\Support\Importe')

@php
    $pendientes = $venta->lineas->sum(fn($linea) => $linea->cantidadPendienteDeDevolver());
    $retenido = $venta->pagado();
    $porcentaje = $venta->descuentoPorcentaje();
@endphp

@section('title', "Devolución — {$venta->numeroFormateado()}")

@section('content')
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Registrar una devolución</h1>
        <p class="text-body-secondary small mb-0">
            Venta <span class="fw-semibold">{{ $venta->numeroFormateado() }}</span> a
            <span class="fw-semibold">{{ $venta->clienteTexto() }}</span>.
            Cargá <strong>cuántas unidades vuelven</strong> de cada renglón: con eso el stock
            vuelve al depósito, se devuelve la plata que corresponde y la venta cambia de estado.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ route('ventas.devolver', $venta) }}">
        @csrf

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body pb-2">
                <span class="text-body-secondary small d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i> Ingresá las unidades físicas que devolvió el cliente
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Los campos arrancan vacíos para registrar lo efectivamente traído. Mueve stock de vuelta al depósito y registra el reintegro de dinero."
                        aria-label="Ayuda sobre devoluciones">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">
                        Productos de la venta {{ $venta->numeroFormateado() }}, con cuántas unidades vuelven
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Vendidas</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Ya devueltas</th>
                            <th scope="col" class="text-end">Pendientes</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">Precio unitario</th>
                            <th scope="col" class="text-end">Vuelven ahora</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($venta->lineas as $linea)
                            @php($pendiente = $linea->cantidadPendienteDeDevolver())
                            <tr>
                                <td>
                                    {{-- La descripción congelada: sigue diciendo qué se
                                         vendió aunque el producto se haya renombrado. --}}
                                    <span class="fw-semibold">{{ $linea->descripcion }}</span>
                                    <div class="small text-body-secondary">{{ $linea->producto->codigo }}</div>
                                </td>

                                <td class="text-end">{{ $linea->cantidad }}</td>
                                <td class="text-end d-none d-md-table-cell">{{ $linea->cantidad_devuelta }}</td>
                                <td class="text-end">{{ $pendiente }}</td>
                                <td class="text-end text-nowrap d-none d-md-table-cell">
                                    {{ Importe::pesos($linea->precio_unitario) }}
                                </td>

                                <td class="text-end" style="width: 9rem;">
                                    <label class="visually-hidden" for="cantidad_{{ $linea->id }}">
                                        Unidades de {{ $linea->descripcion }} que vuelven
                                    </label>

                                    @if ($pendiente === 0)
                                        {{-- Un renglón ya devuelto por completo no admite
                                             más, así que no se ofrece el campo. Un input
                                             deshabilitado no viaja en el POST, y el
                                             servicio lo rechazaría igual. --}}
                                        <span class="badge text-bg-warning">Devuelta</span>
                                    @else
                                        <input type="number"
                                            class="form-control form-control-sm text-end @error("cantidades.{$linea->id}") is-invalid @enderror"
                                            id="cantidad_{{ $linea->id }}" name="cantidades[{{ $linea->id }}]"
                                            value="{{ old("cantidades.{$linea->id}") }}" min="0"
                                            max="{{ $pendiente }}" step="1" inputmode="numeric">

                                        @error("cantidades.{$linea->id}")
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
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
                    Pendientes de devolver en total: <span class="fw-semibold">{{ $pendientes }}</span> unidad(es).
                </p>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card card-body border-0 shadow-sm h-100">
                    <h2 class="h6 text-body-secondary">La plata que vuelve</h2>

                    <dl class="row small mb-2">
                        <dt class="col-7 fw-normal text-body-secondary">Total de la venta</dt>
                        <dd class="col-5 text-end mb-1">{{ Importe::pesos($venta->total) }}</dd>

                        @if ((float) $venta->descuento > 0)
                            <dt class="col-7 fw-normal text-body-secondary">
                                Descuento aplicado
                            </dt>
                            <dd class="col-5 text-end mb-1">{{ Importe::paraFormulario($porcentaje) }} %</dd>
                        @endif

                        <dt class="col-7 fw-semibold border-top pt-2">Quedó cobrado</dt>
                        <dd class="col-5 text-end fw-semibold border-top pt-2 mb-0">
                            {{ Importe::pesos($retenido) }}
                        </dd>
                    </dl>

                    <p class="text-body-secondary small mb-0">
                        <strong>El monto lo calcula el sistema</strong> y no se escribe: es el precio de
                        las unidades que vuelven
                        @if ((float) $venta->descuento > 0)
                            menos su parte proporcional del descuento, para que devolver de a poco no
                            salga más caro que devolver todo junto
                        @endif
                        . Si esta devolución completa la venta, vuelve exactamente lo que quedó
                        cobrado, para que el libro cierre en cero.
                    </p>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card card-body border-0 shadow-sm h-100">
                    <h2 class="h6 text-body-secondary">Cómo vuelve</h2>

                    <div class="mb-3">
                        <label for="metodo" class="form-label">Medio por el que vuelve la plata</label>

                        {{-- Sin `required`: tiene valor por omisión, así que la opción
                             vacía no haría falta y el selector nunca está sin elegir. --}}
                        <select class="form-select @error('metodo') is-invalid @enderror" id="metodo" name="metodo"
                            aria-describedby="ayudaMetodo @error('metodo') errorMetodo @enderror">
                            @foreach ($medios as $clave)
                                <option value="{{ $clave }}" @selected(old('metodo', $sugerido) === $clave)>
                                    {{ Pago::METODOS[$clave] }}
                                </option>
                            @endforeach
                        </select>

                        <div id="ayudaMetodo" class="form-text">
                            Cambiá el medio si devolvés el dinero por otra vía.
                        </div>

                        @error('metodo')
                            <div id="errorMetodo" class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label for="motivo" class="form-label">
                            Motivo <span class="text-body-secondary fw-normal">(opcional)</span>
                        </label>

                        <textarea class="form-control @error('motivo') is-invalid @enderror" id="motivo" name="motivo" rows="2"
                            maxlength="255" aria-describedby="ayudaMotivo @error('motivo') errorMotivo @enderror"
                            placeholder="Vino fallado, no era el modelo que pidió…">{{ old('motivo') }}</textarea>

                        <div id="ayudaMotivo" class="form-text">
                            Se registra en el kardex de stock de cada producto reintegrado.
                        </div>

                        @error('motivo')
                            <div id="errorMotivo" class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-secondary d-flex align-items-start gap-2 small py-2 px-3 mt-4 mb-0 border-0 bg-body-tertiary">
            <i class="bi bi-info-circle text-body-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div>
                El estado (<strong>parcial</strong> o <strong>total</strong>) se calcula automáticamente según las unidades. La venta original no se anula: se asienta el reintegro correspondiente.
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-danger px-4" onclick="return confirm(@js('La devolución no se puede deshacer: el stock vuelve al depósito y la plata se devuelve. ¿Seguimos?'));">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Registrar devolución
            </button>
        </div>
    </form>
@endsection
