@extends('layouts.app')

@use('App\Models\PoliticaPrecios')

{{-- Shell (regla 37): la política, los impuestos y los factores los trae pantallas/precios.js. --}}

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

    <div data-pantalla="precios">
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
                {{-- Envía por la API: los errores los marca el módulo central en cada campo. --}}
                <form method="POST" novalidate class="card-body pt-2" data-formulario-politica>
                    <p class="alert alert-danger" data-errores-formulario hidden></p>

                    <div class="row g-3">
                        {{-- Ganancia global: el multiplicador, que es como piensa el mostrador --}}
                        <div class="col-12 col-md-3">
                            <label for="ganancia_global" class="form-label">
                                Ganancia <span class="text-danger" aria-hidden="true">*</span>
                            </label>

                            <div class="input-group has-validation">
                                <input type="text" inputmode="decimal" class="form-control" id="ganancia_global"
                                    name="ganancia_global" required aria-describedby="ayudaGanancia">

                                <span class="input-group-text bg-light text-body-secondary px-2">
                                    <span id="badgeMargen"
                                        class="badge bg-success-subtle text-success-emphasis border border-success-subtle"
                                        data-guardado="" title="Margen sobre la venta">
                                        Margen: <span id="margenValor">—</span>%
                                    </span>
                                </span>
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

                            <div class="input-group has-validation">
                                <input type="text" inputmode="decimal" class="form-control" id="recargo_lista"
                                    name="recargo_lista" required aria-describedby="ayudaRecargo">
                                <span class="input-group-text">%</span>
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

                            <input type="text" inputmode="decimal" class="form-control" id="redondeo_multiplo"
                                name="redondeo_multiplo" required aria-describedby="ayudaMultiplo">

                            <div id="ayudaMultiplo" class="form-text">
                                Ej: 100 redondea para terminar en cero.
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <label for="redondeo_modo" class="form-label">
                                Hacia dónde <span class="text-danger" aria-hidden="true">*</span>
                            </label>

                            <select class="form-select" id="redondeo_modo" name="redondeo_modo" required>
                                @foreach (PoliticaPrecios::MODOS_REDONDEO as $valor => $texto)
                                    <option value="{{ $valor }}">{{ $texto }}</option>
                                @endforeach
                            </select>
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
                <div class="card-body pt-2" data-politica-lectura>
                    <dl class="row mb-0 small">
                        <dt class="col-6 col-md-3 fw-normal text-body-secondary">Ganancia</dt>
                        <dd class="col-6 col-md-3 fw-semibold">
                            × <span data-campo="ganancia_global">…</span>
                            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle ms-1">
                                Margen: <span data-campo="margen_global">…</span> %
                            </span>
                        </dd>

                        <dt class="col-6 col-md-3 fw-normal text-body-secondary">Recargo del precio de lista</dt>
                        <dd class="col-6 col-md-3 fw-semibold"><span data-campo="recargo_lista">…</span> %</dd>

                        <dt class="col-6 col-md-3 fw-normal text-body-secondary">Redondeo</dt>
                        <dd class="col-6 col-md-9 fw-semibold mb-0">
                            Múltiplo de <span data-campo="redondeo_multiplo">…</span>,
                            <span data-campo="redondeo_modo_texto"></span>
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
                    <tbody data-impuestos data-columnas="7"
                        data-vacio="No hay impuestos cargados. Sin ninguno, el precio final es el neto.">
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Una fila, con un @can por acción (regla 12). Los textos los pone el JS con textContent. --}}
            <template data-impuesto-fila>
                <tr>
                    <td class="fw-semibold" data-campo="nombre"></td>
                    <td class="text-end"><span data-campo="alicuota"></span> %</td>
                    <td><span class="badge" data-campo="alcance"></span></td>
                    <td class="d-none d-md-table-cell small text-body-secondary" data-campo="comprobante"></td>

                    {{-- Cuántos productos lo usan: es lo que dice qué se rompe al darlo de baja --}}
                    <td class="text-center d-none d-md-table-cell">
                        <span class="text-body-secondary" aria-label="No aplica" data-sin-uso>—</span>
                        <a href="{{ route('productos.index') }}" class="text-decoration-none" data-campo="productos" hidden></a>
                    </td>

                    <td><span class="badge" data-campo="estado"></span></td>

                    <td class="text-end text-nowrap">
                        @can('precio.editar')
                            <a href="{{ route('precios.impuestos.edit', ['impuesto' => '__ID__']) }}" data-accion="editar"
                                class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                <span class="d-none d-sm-inline">Editar</span>
                            </a>
                        @endcan

                        @can('precio.editar')
                            <button type="button" class="btn btn-sm btn-outline-danger" data-eliminar
                                data-ruta="/impuestos/__ID__">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        @endcan
                    </td>
                </tr>
            </template>
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
                    <tbody data-factores data-columnas="6"
                        data-vacio="Todavía no hay factores. El neto se forma sólo con la ganancia.">
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template data-factor-fila>
                <tr>
                    <td class="fw-semibold" data-campo="nombre"></td>
                    <td class="d-none d-md-table-cell small text-body-secondary" data-campo="modo"></td>
                    <td class="text-end"><span data-campo="porcentaje"></span> %</td>

                    {{-- La operación escrita: vuelve visible que un recupero divide (con 3 %, × 1,03 recupera 2,91 %). --}}
                    <td class="text-end font-monospace small" data-campo="operacion"></td>

                    <td><span class="badge" data-campo="estado"></span></td>

                    <td class="text-end text-nowrap">
                        @can('precio.editar')
                            <a href="{{ route('precios.factores.edit', ['factor' => '__ID__']) }}" data-accion="editar"
                                class="btn btn-sm btn-outline-dark">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                <span class="d-none d-sm-inline">Editar</span>
                            </a>
                        @endcan

                        @can('precio.editar')
                            <button type="button" class="btn btn-sm btn-outline-danger" data-eliminar
                                data-ruta="/factores-costo/__ID__">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </button>
                        @endcan
                    </td>
                </tr>
            </template>
        </section>
    </div>
@endsection
