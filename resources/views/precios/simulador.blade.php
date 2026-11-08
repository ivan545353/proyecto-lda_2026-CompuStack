@extends('layouts.app')

@use('App\Support\Importe')

@section('title', 'Simulador de precios')

@section('content')
    <a href="{{ route('precios.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a precios
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Simulador</h1>
        <p class="text-body-secondary small mb-0">
            Probá un costo y mirá qué precio sale, con el desglose completo. No guarda nada
            ni toca ningún producto.
        </p>
    </div>

    @include('partials.errores')

    {{-- GET a propósito: el resultado queda en la URL y se puede compartir. --}}
    <form method="GET" action="{{ route('precios.simulador') }}" novalidate class="card card-body border-0 shadow-sm mb-4">
        <input type="hidden" name="simular" value="1">

        <div class="row g-3 align-items-start">
            <div class="col-12 col-md-4">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="costo" class="form-label mb-0">
                        Costo neto <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Cargalo sin IVA: el IVA de compra es crédito fiscal. Si lo incluís, el precio final duplicará el impuesto."
                        aria-label="Ayuda sobre costo neto">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="input-group">
                    <span class="input-group-text">$</span>
                    <input type="text" inputmode="decimal" class="form-control @error('costo') is-invalid @enderror"
                        id="costo" name="costo" value="{{ old('costo', Importe::paraFormulario($costo)) }}" required
                        autofocus aria-describedby="ayudaCosto @error('costo') errorCosto @enderror">

                    @error('costo')
                        <div id="errorCosto" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div id="ayudaCosto" class="form-text">
                    Sin IVA (crédito fiscal).
                </div>
            </div>

            <div class="col-12 col-md-4">
                <label for="ganancia" class="form-label">Ganancia</label>

                <input type="text" inputmode="decimal" class="form-control @error('ganancia') is-invalid @enderror"
                    id="ganancia" name="ganancia" value="{{ old('ganancia', request('ganancia')) }}"
                    placeholder="{{ Importe::numero($politica->ganancia_global) }}"
                    aria-describedby="ayudaGananciaSim @error('ganancia') errorGananciaSim @enderror">

                <div id="ayudaGananciaSim" class="form-text">
                    Vacía para usar la global (× {{ Importe::numero($politica->ganancia_global) }}).
                </div>

                @error('ganancia')
                    <div id="errorGananciaSim" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-4">
                <label for="alicuota_iva" class="form-label">
                    Alícuota de IVA <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <select class="form-select @error('alicuota_iva') is-invalid @enderror" id="alicuota_iva"
                    name="alicuota_iva" required aria-describedby="@error('alicuota_iva') errorAlicuotaSim @enderror">
                    @foreach ($alicuotas as $valor => $texto)
                        <option value="{{ $valor }}" @selected((string) old('alicuota_iva', $alicuota) === (string) $valor)>
                            {{ $texto }}
                        </option>
                    @endforeach
                </select>

                @error('alicuota_iva')
                    <div id="errorAlicuotaSim" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            @if ($calculo !== null)
                <a href="{{ route('precios.simulador') }}" class="btn btn-outline-secondary">Limpiar</a>
            @endif

            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-calculator" aria-hidden="true"></i> Calcular
            </button>
        </div>
    </form>

    @if ($calculo === null)
        {{-- Vacío por falta de datos, no por filtro: dice otra cosa. --}}
        <p class="text-body-secondary text-center py-4 mb-0">
            Cargá un costo y presioná «Calcular» para ver el precio con su desglose.
        </p>
    @else
        <div class="row g-4">
            {{-- ------------------------------------------------------------ --}}
            {{-- Cómo se forma el neto                                         --}}
            {{-- ------------------------------------------------------------ --}}
            <div class="col-12 col-lg-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-0">Cómo se forma el neto</h2>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Del costo neto al neto de venta</caption>
                            <tbody>
                                <tr>
                                    <th scope="row" class="fw-normal">Costo neto</th>
                                    <td class="text-end">{{ Importe::pesos($calculo['costo_neto']) }}</td>
                                </tr>

                                <tr>
                                    <th scope="row" class="fw-normal">
                                        Ganancia
                                        <span class="text-body-secondary">×
                                            {{ Importe::numero($calculo['ganancia']) }}</span>
                                    </th>
                                    <td class="text-end text-body-secondary">
                                        {{ Importe::pesos(round($calculo['costo_neto'] * $calculo['ganancia'], 2)) }}
                                    </td>
                                </tr>

                                {{-- Los factores de costo, cada uno con lo que sumó. No van en el
                                     comprobante: viven adentro del precio. --}}
                                @forelse ($calculo['factores'] as $factor)
                                    <tr>
                                        <th scope="row" class="fw-normal">
                                            {{ $factor['nombre'] }}
                                            <span class="text-body-secondary">
                                                {{ $factor['modo'] === 'recupero' ? '÷' : '×' }}
                                                {{ Importe::numero(
                                                    $factor['modo'] === 'recupero' ? 1 - $factor['porcentaje'] / 100 : 1 + $factor['porcentaje'] / 100,
                                                    4,
                                                ) }}
                                            </span>
                                        </th>
                                        <td class="text-end text-body-secondary">
                                            + {{ Importe::pesos($factor['importe']) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="text-body-secondary small">
                                            No hay factores de costo activos.
                                        </td>
                                    </tr>
                                @endforelse

                                <tr class="border-top">
                                    <th scope="row">Neto de venta</th>
                                    <td class="text-end fw-semibold">{{ Importe::pesos($calculo['contado']['neto']) }}</td>
                                </tr>

                                <tr>
                                    <th scope="row" class="fw-normal">Margen sobre la venta</th>
                                    <td class="text-end">{{ Importe::numero($calculo['margen'], 2) }} %</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {{-- ------------------------------------------------------------ --}}
            {{-- El desglose del comprobante                                   --}}
            {{-- ------------------------------------------------------------ --}}
            <div class="col-12 col-lg-6">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-0 pt-3">
                        <h2 class="h6 mb-1">Precio de contado</h2>
                        <p class="text-body-secondary small mb-0">Efectivo, transferencia o QR.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Desglose del precio de contado</caption>
                            <tbody>
                                <tr>
                                    <th scope="row" class="fw-normal">Neto</th>
                                    <td class="text-end">{{ Importe::pesos($calculo['contado']['neto']) }}</td>
                                </tr>

                                @foreach ($calculo['contado']['impuestos'] as $impuesto)
                                    <tr>
                                        <th scope="row" class="fw-normal">
                                            {{ $impuesto['nombre'] }}
                                            <span class="text-body-secondary">
                                                {{ Importe::numero($impuesto['alicuota'], 2) }} %
                                            </span>
                                        </th>
                                        <td class="text-end">{{ Importe::pesos($impuesto['importe']) }}</td>
                                    </tr>
                                @endforeach

                                <tr class="border-top">
                                    <th scope="row" class="h6 mb-0">Precio final</th>
                                    <td class="text-end h6 mb-0">{{ Importe::pesos($calculo['contado']['final']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card-header bg-white border-0 border-top pt-3">
                        <h2 class="h6 mb-1">Precio de lista</h2>
                        <p class="text-body-secondary small mb-0">
                            Tarjeta y cuotas: el de contado con
                            {{ Importe::numero($politica->recargo_lista) }} % de recargo adentro del neto.
                        </p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Desglose del precio de lista</caption>
                            <tbody>
                                <tr>
                                    <th scope="row" class="fw-normal">Neto</th>
                                    <td class="text-end">{{ Importe::pesos($calculo['lista']['neto']) }}</td>
                                </tr>

                                @foreach ($calculo['lista']['impuestos'] as $impuesto)
                                    <tr>
                                        <th scope="row" class="fw-normal">
                                            {{ $impuesto['nombre'] }}
                                            <span class="text-body-secondary">
                                                {{ Importe::numero($impuesto['alicuota'], 2) }} %
                                            </span>
                                        </th>
                                        <td class="text-end">{{ Importe::pesos($impuesto['importe']) }}</td>
                                    </tr>
                                @endforeach

                                <tr class="border-top">
                                    <th scope="row" class="h6 mb-0">Precio final</th>
                                    <td class="text-end h6 mb-0">{{ Importe::pesos($calculo['lista']['final']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <p class="text-body-secondary small mt-3 mb-0">
            El redondeo se aplica al precio final, múltiplo de
            {{ Importe::numero($politica->redondeo_multiplo, 2) }},
            {{ \App\Models\PoliticaPrecios::MODOS_REDONDEO[$politica->redondeo_modo] }}.
        </p>
    @endif
@endsection
