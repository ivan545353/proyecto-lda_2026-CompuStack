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

                <div class="alert alert-light border small text-body-secondary mb-0 mt-3 d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
                    <div>
                        Monto exacto: cargá lo que vale la venta, no lo que te dieron.
                        El vuelto se calcula en pantalla y no se registra.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            {{-- La propuesta es del servidor y va en el value: sin JavaScript también llega.
                 Si la pantalla vuelve de un rechazo, muestra lo que se mandó y no propone
                 de nuevo: re-proponer el saldo sumaría dos veces lo que el cajero repartió. --}}
            @php
                $hayDevueltos = session()->hasOldInput('pagos');
                $propuesta = $saldo > 0 ? Importe::paraFormulario($saldo) : '';
                $valorDe = fn(int $indice, string $clave) => $hayDevueltos
                    ? old("pagos.{$indice}.monto")
                    : ($clave === 'efectivo'
                        ? $propuesta
                        : '');
                $principal = array_filter($medios, fn(string $clave) => $clave === 'efectivo');
                $adicionales = array_diff_key($medios, $principal);
                $abrirAdicionales = collect($adicionales)
                    ->keys()
                    ->contains(
                        fn(int $indice) => filled(old("pagos.{$indice}.monto")) ||
                            $errors->has("pagos.{$indice}.monto"),
                    );
            @endphp

            <form method="POST" action="{{ route('pagos.store', $venta) }}" class="card card-body border-0 shadow-sm h-100"
                data-cobro data-cobro-saldo="{{ number_format($saldo, 2, '.', '') }}">
                @csrf

                <h2 class="h6 text-body-secondary">Con qué se pagó</h2>

                <div class="row g-3">
                    @foreach ($principal as $indice => $clave)
                        @include('ventas._medio-cobro', [
                            'valor' => $valorDe($indice, $clave),
                            'propuesto' => !$hayDevueltos && $propuesta !== '',
                            'autofocus' => $problemas === [] && !$errors->any(),
                            'ayuda' => 'Ya trae el saldo entero. Borralo si no entró efectivo.',
                        ])
                    @endforeach

                    {{-- El vuelto se muestra y no se guarda: los botones no tienen name y
                         nada de este bloque viaja. Sólo funciona con JavaScript. --}}
                    <div class="col-12 col-sm-6" data-cobro-billetes hidden>
                        <p class="form-label mb-2" id="billetesTitulo">Con qué billetes pagó</p>

                        <div class="d-flex flex-wrap gap-2" role="group" aria-labelledby="billetesTitulo">
                            @foreach (config('venta.cobro.billetes') as $billete)
                                <button type="button" class="btn btn-sm btn-outline-dark"
                                    data-billete="{{ $billete }}"
                                    aria-label="Sumar un billete de {{ Importe::pesos($billete) }}">
                                    $ {{ number_format($billete, 0, ',', '.') }}
                                </button>
                            @endforeach

                            <button type="button" class="btn btn-sm btn-link link-secondary" data-billetes-borrar
                                aria-label="Borrar los billetes sumados">
                                Borrar
                            </button>
                        </div>

                        <p class="small mb-0 mt-2" data-cobro-vuelto aria-live="polite"></p>
                    </div>
                </div>

                {{-- `details` y no un collapse de Bootstrap: sin JavaScript, un collapse
                     queda cerrado para siempre. Nace abierto si trae un importe o un error. --}}
                <details class="mt-3" @if ($abrirAdicionales) open @endif>
                    <summary class="fw-semibold">Dividir el pago o cobrar por otro medio</summary>

                    <div class="row g-3 mt-1">
                        @foreach ($adicionales as $indice => $clave)
                            @include('ventas._medio-cobro', [
                                'valor' => $valorDe($indice, $clave),
                                'propuesto' => false,
                                'autofocus' => false,
                                'ayuda' => 'Vacío si por este medio no entró nada.',
                            ])
                        @endforeach
                    </div>
                </details>

                <p class="small mt-3 mb-0" data-cobro-suma aria-live="polite" hidden></p>

                <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-outline-secondary">
                        Cancelar
                    </a>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> Registrar el cobro
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
