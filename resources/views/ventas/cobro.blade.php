@extends('layouts.app')

@use('App\Models\Pago')
@use('App\Support\Importe')

@section('title', "Cobrar {$venta->numeroFormateado()}")

@section('content')
    {{-- Arriba y no sólo abajo: pendiente #14 atendido en la pantalla nueva. --}}
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Cobrar la venta {{ $venta->numeroFormateado() }}</h1>
        <p class="text-body-secondary small mb-0">
            A <span class="fw-semibold">{{ $venta->clienteTexto() }}</span>.
            Al registrar el cobro, la venta pasa a <strong>pagada</strong> y
            <strong>se descuenta el stock</strong> de cada producto.
        </p>
    </div>

    @include('partials.errores')

    {{-- Los avisos de lo que va a fallar, antes de que cargue un solo monto. Las dos
         causas se arreglan en otra pantalla, así que enterarse después de tipear es
         enterarse tarde. No impide enviar: el disponible puede cambiar mientras esta
         pantalla está abierta, y el que decide es el servidor con la fila bloqueada. --}}
    @if ($problemas !== [])
        <div class="alert alert-warning d-flex gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>

            <div>
                <p class="fw-semibold mb-1">
                    Así como está, este cobro va a ser rechazado:
                </p>

                <ul class="mb-0 ps-3">
                    @foreach ($problemas as $problema)
                        <li>{{ $problema }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <div class="card card-body border-0 shadow-sm h-100">
                <h2 class="h6 text-body-secondary">Lo que se cobra</h2>

                <dl class="row mb-0">
                    <dt class="col-7 fw-normal text-body-secondary">Total de la venta</dt>
                    <dd class="col-5 text-end mb-1">{{ Importe::pesos($venta->total) }}</dd>

                    {{-- Sólo si hay algo cobrado. Por el camino normal no puede
                         haberlo —no hay pagos parciales— pero el saldo se lee de los
                         pagos registrados y la pantalla dice lo que el saldo dice. --}}
                    @if ($venta->pagado() != 0)
                        <dt class="col-7 fw-normal text-body-secondary">Ya registrado</dt>
                        <dd class="col-5 text-end mb-1">{{ Importe::pesos($venta->pagado()) }}</dd>
                    @endif

                    <dt class="col-7 fw-semibold border-top pt-2">A cobrar</dt>
                    <dd class="col-5 text-end fw-semibold fs-5 border-top pt-2 mb-0">
                        {{ Importe::pesos($saldo) }}
                    </dd>
                </dl>

                <p class="text-body-secondary small mb-0 mt-3">
                    El cobro tiene que ser por este monto exacto. Se puede repartir entre varios
                    medios, pero la suma tiene que dar justo:
                    <strong>el sistema no registra el vuelto</strong>, así que se carga lo que vale la
                    venta y no lo que el cliente puso sobre el mostrador.
                </p>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <form method="POST" action="{{ route('pagos.store', $venta) }}"
                class="card card-body border-0 shadow-sm h-100">
                @csrf

                <h2 class="h6 text-body-secondary">Con qué se pagó</h2>

                <div class="row g-3">
                    {{-- Una fila por medio ofrecido, con el medio fijo y el monto
                         vacío. Las filas sin monto se descartan en el Form Request,
                         así que «un solo medio por cobro» se cumple por construcción
                         y no hay armador de filas que mantener: la pantalla funciona
                         sin una línea de JavaScript.

                         `mercadopago` no aparece aunque esté en el ENUM: sin
                         integración, un cobro por ese medio afirmaría una
                         acreditación que el sistema no conoce. --}}
                    @foreach ($medios as $indice => $clave)
                        @php
                            $campo = "pagos.{$indice}.monto";
                            $tieneError = $errors->has($campo);
                        @endphp

                        <div class="col-12 col-sm-6">
                            <input type="hidden" name="pagos[{{ $indice }}][metodo]" value="{{ $clave }}">

                            <label for="monto-{{ $clave }}" class="form-label">
                                {{ Pago::METODOS[$clave] }}
                            </label>

                            <div class="input-group">
                                <span class="input-group-text" aria-hidden="true">$</span>

                                {{-- Campo de dinero: `text` con `inputmode="decimal"`,
                                     nunca `number`. Un `number` redondea, rechaza la
                                     coma del formato argentino y en algunos
                                     navegadores cambia el valor con la rueda del
                                     mouse. --}}
                                <input type="text" inputmode="decimal"
                                    class="form-control @error($campo) is-invalid @enderror" id="monto-{{ $clave }}"
                                    name="pagos[{{ $indice }}][monto]" value="{{ old($campo) }}"
                                    placeholder="0,00"
                                    aria-describedby="ayuda-{{ $clave }}@if ($tieneError) error-{{ $clave }} @endif">
                            </div>

                            <div id="ayuda-{{ $clave }}" class="form-text">
                                Vacío si por este medio no entró nada.
                            </div>

                            @error($campo)
                                <div id="error-{{ $clave }}" class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach
                </div>

                <div class="d-grid d-sm-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-acento">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> Registrar el cobro
                    </button>

                    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">
                        Cancelar
                    </a>
                </div>

            </form>
        </div>
    </div>
@endsection
