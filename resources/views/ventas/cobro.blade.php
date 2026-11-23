@extends('layouts.app')

@use('App\Models\Pago')
@use('App\Support\Importe')

@section('title', 'Cobrar la venta')

{{-- Shell (tramo H, regla 37): el saldo, el medio principal y lo que va a hacer fallar el cobro llegan en
     la ficha (`GET /api/v1/ventas/{venta}`, bloque `cobro`); cobrar es `POST /api/v1/ventas/{venta}/pagos`.
     Los medios y los billetes son constantes y configuración, no datos de negocio. --}}
@section('content')
    {{-- Arriba y no sólo abajo: pendiente #14 atendido en la pantalla nueva. --}}
    <a href="{{ route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la venta
    </a>

    <div data-pantalla="cobro-venta" data-venta="{{ $venta->id }}" data-ficha="{{ route('ventas.show', $venta) }}"
        aria-busy="true">
        <div class="mb-4">
            <h1 class="h3 mb-1">Cobrar la venta <span data-campo="numero"></span></h1>
            <p class="text-body-secondary small mb-0">
                A <span class="fw-semibold" data-campo="cliente_texto"></span>.
                Al registrar el cobro, la venta pasa a <strong>pagada</strong> y
                <strong>se descuenta el stock</strong> de cada producto.
            </p>
        </div>

        <p class="text-body-secondary" data-cargando>Cargando la venta…</p>

        {{-- Los avisos de lo que va a fallar, antes de que cargue un solo monto. Las dos
             causas se arreglan en otra pantalla, así que enterarse después de tipear es
             enterarse tarde. No impide enviar: el disponible puede cambiar mientras esta
             pantalla está abierta, y el que decide es el servidor con la fila bloqueada. --}}
        <div class="alert alert-warning d-flex gap-3" role="alert" data-problemas hidden>
            <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>

            <div>
                <p class="fw-semibold mb-1">
                    Así como está, este cobro va a ser rechazado:
                </p>

                <ul class="mb-0 ps-3" data-lista-problemas></ul>
            </div>
        </div>

        <div class="row g-3" data-ficha hidden>
            <div class="col-12 col-lg-5">
                <div class="card card-body border-0 shadow-sm h-100">
                    <h2 class="h6 text-body-secondary">Lo que se cobra</h2>

                    <dl class="row mb-0">
                        <dt class="col-7 fw-normal text-body-secondary">Total de la venta</dt>
                        <dd class="col-5 text-end mb-1" data-campo="total"></dd>

                        {{-- Sólo si hay algo cobrado. Por el camino normal no puede
                             haberlo —no hay pagos parciales— pero el saldo se lee de los
                             pagos registrados y la pantalla dice lo que el saldo dice. --}}
                        <dt class="col-7 fw-normal text-body-secondary" data-si="pagado" hidden>Ya registrado</dt>
                        <dd class="col-5 text-end mb-1" data-si="pagado" data-campo="pagado" hidden></dd>

                        <dt class="col-7 fw-semibold border-top pt-2">A cobrar</dt>
                        <dd class="col-5 text-end fw-semibold fs-5 border-top pt-2 mb-0" data-campo="saldo"></dd>
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
                {{-- La propuesta la pone el JS con el saldo de la ficha. Si el servidor rechaza el cobro,
                     la pantalla no se recarga: queda lo que se mandó y no se propone de nuevo, porque
                     re-proponer el saldo sumaría dos veces lo que el cajero repartió. --}}
                <form novalidate class="card card-body border-0 shadow-sm h-100" data-cobro data-cobro-saldo="0">
                    <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

                    <h2 class="h6 text-body-secondary">Con qué se pagó</h2>

                    <div class="row g-3" data-medio-principal
                        data-ayuda-efectivo="Ya trae el saldo entero. Borralo si no entró efectivo."
                        data-ayuda-transferencia="Ya trae el saldo entero: es lo que el pedido dijo que transfirió. Revisá el comprobante antes.">

                        {{-- El vuelto se muestra y no se guarda: los botones no tienen name y
                             nada de este bloque viaja. Sólo funciona con JavaScript, y sólo con el
                             efectivo como medio principal (el JS lo saca si no). --}}
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

                    {{-- `details` y no un collapse de Bootstrap. Nace plegado: la pantalla no
                         vuelve de un rechazo, así que nunca trae un importe en los otros medios. --}}
                    <details class="mt-3">
                        <summary class="fw-semibold">Dividir el pago o cobrar por otro medio</summary>

                        <div class="row g-3 mt-1" data-medios-adicionales>
                            @foreach (Pago::METODOS_EN_USO as $indice => $clave)
                                @include('ventas._medio-cobro', ['indice' => $indice, 'clave' => $clave])
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
    </div>
@endsection
