@extends('layouts.app')

@use('App\Models\FactorCosto')
@use('App\Models\Impuesto')
@use('App\Models\PoliticaPrecios')
@use('App\Support\Importe')

@section('title', 'Precios')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Precios</h1>
            <p class="text-body-secondary small mb-0">
                De acá sale el precio de venta de cada producto.
            </p>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2">
            @can('precio.ajustar_masivo')
                <a href="{{ route('precios.ajuste') }}" class="btn btn-outline-dark text-nowrap">
                    <i class="bi bi-percent" aria-hidden="true"></i> Ajuste masivo
                </a>
            @endcan

            <a href="{{ route('precios.lotes') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Historial de ajustes
            </a>

            <a href="{{ route('precios.simulador') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-calculator" aria-hidden="true"></i> Simulador
            </a>
        </div>
    </div>

    @include('partials.errores')

    {{-- Advertencia operativa visible y fórmula matemática plegable --}}
    <div class="alert alert-light border mb-4" role="note">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
            <div class="d-flex align-items-center gap-2 small">
                <i class="bi bi-exclamation-triangle text-warning flex-shrink-0 fs-6" aria-hidden="true"></i>
                <span>
                    <strong>Atención:</strong> Los cambios realizados Acá solo se aplicarán a productos nuevos. Los precios
                    ya cargados no se actualizarán automáticamente.
                </span>
            </div>

            <button
                class="btn btn-link btn-sm text-decoration-none text-body-secondary p-0 text-nowrap d-inline-flex align-items-center gap-1 align-self-start align-self-sm-auto"
                type="button" data-bs-toggle="collapse" data-bs-target="#comoSeCalcula" aria-expanded="false"
                aria-controls="comoSeCalcula">
                <i class="bi bi-question-circle" aria-hidden="true"></i>
                <span>¿Cómo se calcula el precio?</span>
            </button>
        </div>

        <div class="collapse pt-2 mt-2 border-top small text-body-secondary" id="comoSeCalcula">
            <strong>Costo neto × ganancia × factores</strong> da el neto;
            <strong>el neto × los impuestos</strong> da el precio final, que se redondea;
            y el desglose del comprobante se deriva hacia atrás de ese precio final, para que
            neto más impuestos dé exactamente lo que se cobra.
        </div>
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- La política del comercio                                          --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
            <h2 class="h5 mb-0">Los números del comercio</h2>
            <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                data-bs-toggle="tooltip" data-bs-placement="top"
                data-bs-title="Valen para todo el catálogo. La ganancia se puede pisar por categoría y por producto."
                aria-label="Información sobre los números del comercio">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
            </button>
        </div>

        @can('precio.editar')
            <form method="POST" action="{{ route('precios.politica.update') }}" novalidate class="card-body pt-2">
                @csrf
                @method('PUT')

                <div class="row g-3">
                    {{-- Ganancia global: el multiplicador, que es como piensa el mostrador --}}
                    <div class="col-12 col-md-3">
                        <label for="ganancia_global" class="form-label">
                            Ganancia <span class="text-danger" aria-hidden="true">*</span>
                        </label>

                        <div class="input-group has-validation">
                            <input type="text" inputmode="decimal"
                                class="form-control @error('ganancia_global') is-invalid @enderror" id="ganancia_global"
                                name="ganancia_global" required
                                value="{{ old('ganancia_global', Importe::numero($politica->ganancia_global)) }}"
                                aria-describedby="ayudaGanancia @error('ganancia_global') errorGanancia @enderror">

                            <span class="input-group-text bg-light text-body-secondary px-2">
                                <span id="badgeMargen"
                                    class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
                                    data-guardado="{{ (float) $politica->ganancia_global }}"
                                    data-margen-guardado="{{ Importe::numero($politica->margenGlobal(), 2) }}"
                                    title="Margen sobre la venta">
                                    Margen: <span id="margenValor">{{ Importe::numero($politica->margenGlobal(), 2) }}</span>%
                                </span>
                            </span>

                            @error('ganancia_global')
                                <div id="errorGanancia" class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div id="ayudaGanancia" class="form-text">
                            Multiplicador sobre el costo base.
                        </div>
                    </div>

                    {{-- Recargo del precio de lista: el costo financiero de vender en cuotas --}}
                    <div class="col-12 col-md-3">
                        <label for="recargo_lista" class="form-label">
                            Recargo del precio de lista <span class="text-danger" aria-hidden="true">*</span>
                        </label>

                        <div class="input-group">
                            <input type="text" inputmode="decimal"
                                class="form-control @error('recargo_lista') is-invalid @enderror" id="recargo_lista"
                                name="recargo_lista" required
                                value="{{ old('recargo_lista', Importe::numero($politica->recargo_lista)) }}"
                                aria-describedby="ayudaRecargo @error('recargo_lista') errorRecargo @enderror">
                            <span class="input-group-text">%</span>

                            @error('recargo_lista')
                                <div id="errorRecargo" class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div id="ayudaRecargo" class="form-text">
                            Lo que cuesta cobrar con tarjeta y en cuotas. En cero, el precio de
                            lista es igual al de contado.
                        </div>
                    </div>

                    {{-- Redondeo: múltiplo y modo --}}
                    <div class="col-12 col-md-3">
                        <label for="redondeo_multiplo" class="form-label">
                            Redondear a múltiplo de <span class="text-danger" aria-hidden="true">*</span>
                        </label>

                        <input type="text" inputmode="decimal"
                            class="form-control @error('redondeo_multiplo') is-invalid @enderror" id="redondeo_multiplo"
                            name="redondeo_multiplo" required
                            value="{{ old('redondeo_multiplo', Importe::numero($politica->redondeo_multiplo, 2)) }}"
                            aria-describedby="ayudaMultiplo @error('redondeo_multiplo') errorMultiplo @enderror">

                        <div id="ayudaMultiplo" class="form-text">
                            Ej: 100 redondea para terminar en cero.
                        </div>

                        @error('redondeo_multiplo')
                            <div id="errorMultiplo" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="redondeo_modo" class="form-label">
                            Hacia dónde <span class="text-danger" aria-hidden="true">*</span>
                        </label>

                        <select class="form-select @error('redondeo_modo') is-invalid @enderror" id="redondeo_modo"
                            name="redondeo_modo" required aria-describedby="@error('redondeo_modo') errorModo @enderror">
                            @foreach (PoliticaPrecios::MODOS_REDONDEO as $valor => $texto)
                                <option value="{{ $valor }}" @selected(old('redondeo_modo', $politica->redondeo_modo) === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>

                        @error('redondeo_modo')
                            <div id="errorModo" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="d-grid d-sm-flex justify-content-sm-end mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Guardar los números
                    </button>
                </div>
            </form>
        @endcan

        {{-- Sin permiso de edición los mismos números se leen, no se tocan. --}}
        @cannot('precio.editar')
            <div class="card-body pt-2">
                <dl class="row mb-0 small">
                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Ganancia</dt>
                    <dd class="col-6 col-md-3 fw-semibold">
                        × {{ Importe::numero($politica->ganancia_global) }}
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-1">
                            Margen: {{ Importe::numero($politica->margenGlobal(), 2) }} %
                        </span>
                    </dd>

                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Recargo del precio de lista</dt>
                    <dd class="col-6 col-md-3 fw-semibold">{{ Importe::numero($politica->recargo_lista) }} %</dd>

                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Redondeo</dt>
                    <dd class="col-6 col-md-9 fw-semibold mb-0">
                        Múltiplo de {{ Importe::numero($politica->redondeo_multiplo, 2) }},
                        {{ PoliticaPrecios::MODOS_REDONDEO[$politica->redondeo_modo] }}
                    </dd>
                </dl>
            </div>
        @endcannot
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Impuestos                                                         --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Impuestos</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Se aplican sobre el neto y se itemizan en el comprobante. Las alícuotas de IVA las elige cada producto; los generales alcanzan a todo el catálogo."
                    aria-label="Información sobre impuestos">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            @can('precio.editar')
                <a href="{{ route('precios.impuestos.create') }}" class="btn btn-sm btn-acento text-nowrap">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo impuesto
                </a>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Impuestos que se aplican sobre el neto</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col" class="text-end">Alícuota</th>
                        <th scope="col">Alcance</th>
                        <th scope="col" class="d-none d-md-table-cell">En el comprobante</th>
                        <th scope="col" class="text-center d-none d-md-table-cell">Productos</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($impuestos as $impuesto)
                        @php($usado = $impuesto->alcance === 'producto' ? (int) ($uso[$impuesto->alicuota] ?? 0) : null)

                        <tr>
                            <td class="fw-semibold">{{ $impuesto->nombre }}</td>

                            <td class="text-end">{{ Importe::numero($impuesto->alicuota, 2) }} %</td>

                            <td>
                                <span
                                    class="badge {{ $impuesto->alcance === 'producto' ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $impuesto->alcance === 'producto' ? 'Por producto' : 'Todo el catálogo' }}
                                </span>
                            </td>

                            <td class="d-none d-md-table-cell small text-body-secondary">
                                {{ Impuesto::COMPROBANTES[$impuesto->comprobante] }}
                            </td>

                            {{-- Cuántos productos lo usan: es lo que dice qué se rompe al darlo de baja --}}
                            <td class="text-center d-none d-md-table-cell">
                                @if ($usado === null)
                                    <span class="text-body-secondary" aria-label="No aplica">—</span>
                                @elseif ($usado === 0)
                                    0
                                @else
                                    <a href="{{ route('productos.index') }}"
                                        class="text-decoration-none">{{ $usado }}</a>
                                @endif
                            </td>

                            <td>
                                <span class="badge {{ $impuesto->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $impuesto->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>

                            <td class="text-end text-nowrap">
                                @can('precio.editar')
                                    <a href="{{ route('precios.impuestos.edit', $impuesto) }}"
                                        class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar el impuesto {{ $impuesto->nombre }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan

                                @can('precio.editar')
                                    <form method="POST" action="{{ route('precios.impuestos.destroy', $impuesto) }}"
                                        class="d-inline" onsubmit="return confirm(@js($usado > 0 ? "«{$impuesto->nombre}» es la alícuota de {$usado} producto(s). No se eliminará: se desactivará. ¿Continuar?" : "¿Eliminar el impuesto «{$impuesto->nombre}»?"));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Eliminar el impuesto {{ $impuesto->nombre }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">
                                No hay impuestos cargados. Sin ninguno, el precio final es el neto.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($impuestos->hasPages())
            <div class="card-body pt-3">{{ $impuestos->links() }}</div>
        @endif
    </section>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Factores de costo                                                 --}}
    {{-- ---------------------------------------------------------------- --}}
    <section class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Factores de costo</h2>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="Forman el neto y no se itemizan: van adentro del precio. Flete, ingresos brutos que el comercio absorbe, lo que haga falta."
                    aria-label="Información sobre factores de costo">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            @can('precio.editar')
                <a href="{{ route('precios.factores.create') }}" class="btn btn-sm btn-acento text-nowrap">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo factor
                </a>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Factores que forman el neto</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col" class="d-none d-md-table-cell">Modo</th>
                        <th scope="col" class="text-end">Porcentaje</th>
                        <th scope="col" class="text-end">Operación</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($factores as $factor)
                        @php($porcentaje = (float) $factor->porcentaje)

                        <tr>
                            <td class="fw-semibold">{{ $factor->nombre }}</td>

                            <td class="d-none d-md-table-cell small text-body-secondary">
                                {{ FactorCosto::MODOS[$factor->modo] }}
                            </td>

                            <td class="text-end">{{ Importe::numero($factor->porcentaje) }} %</td>

                            {{-- La operación escrita: es lo que vuelve visible que un recupero
                                 divide. Con 3 %, multiplicar por 1,03 recupera sólo 2,91 %. --}}
                            <td class="text-end font-monospace small">
                                @if ($factor->modo === 'recupero')
                                    ÷ {{ Importe::numero(1 - $porcentaje / 100, 4) }}
                                @else
                                    × {{ Importe::numero(1 + $porcentaje / 100, 4) }}
                                @endif
                            </td>

                            <td>
                                <span class="badge {{ $factor->activo ? 'text-bg-dark' : 'text-bg-secondary' }}">
                                    {{ $factor->activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>

                            <td class="text-end text-nowrap">
                                @can('precio.editar')
                                    <a href="{{ route('precios.factores.edit', $factor) }}"
                                        class="btn btn-sm btn-outline-dark"
                                        aria-label="Editar el factor {{ $factor->nombre }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                        <span class="d-none d-sm-inline">Editar</span>
                                    </a>
                                @endcan

                                @can('precio.editar')
                                    <form method="POST" action="{{ route('precios.factores.destroy', $factor) }}"
                                        class="d-inline" onsubmit="return confirm(@js("¿Eliminar el factor «{$factor->nombre}»? Los precios que ya lo tienen adentro no cambian hasta que los recalcules."));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Eliminar el factor {{ $factor->nombre }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">
                                Todavía no hay factores. El neto se forma sólo con la ganancia.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($factores->hasPages())
            <div class="card-body pt-3">{{ $factores->links() }}</div>
        @endif
    </section>
@endsection
