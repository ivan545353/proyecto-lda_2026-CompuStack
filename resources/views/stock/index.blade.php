@extends('layouts.app')

@use('App\Models\MovimientoStock')
@use('App\Models\Venta')

{{-- Shell (regla 37): los movimientos, el panel del producto y las opciones de «Lo registró» los trae
     pantallas/stock.js. Lo que viene de la URL es lo que el operador eligió, no datos de negocio. --}}
@php($productoId = request()->integer('producto_id') ?: null)
@php($hayFiltros = request()->hasAny(['producto_id', 'tipo', 'usuario_id', 'canal', 'desde', 'hasta']))

@section('title', 'Movimientos de stock')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Movimientos de stock</h1>
        <p class="text-body-secondary small mb-0">
            Todo lo que entró y salió del depósito, con quién lo registró y por qué.
        </p>
    </div>

    <div data-pantalla="kardex" data-producto="{{ $productoId }}">
        {{-- Cuando el kardex está filtrado por un producto, su situación de stock y el acceso al ajuste
             van acá: el usuario decide con el número a la vista. Nace oculto; lo muestra el JS. --}}
        @if ($productoId)
            <div class="card border-0 shadow-sm mb-4" data-panel-producto hidden>
                <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h2 class="h5 mb-1" data-campo="nombre"></h2>
                        <p class="text-body-secondary small mb-2">Código <span data-campo="codigo"></span></p>

                        <div class="d-flex flex-wrap gap-3 small">
                            <span><strong data-campo="stock"></strong> en depósito</span>
                            <span class="text-body-secondary"><span data-campo="stock_reservado"></span> reservadas</span>
                            <span><strong data-campo="disponible"></strong> disponibles</span>
                            <span class="text-body-secondary">mínimo <span data-campo="stock_minimo"></span></span>
                        </div>

                        {{-- Lo reservado no es un movimiento: sale del depósito recién al cobrar (regla 5, D-25). --}}
                        <p class="small text-body-secondary mb-0 mt-2" data-reservas hidden>
                            De las reservadas, <span data-campo="pedidos"></span> están en pedidos de la tienda sin cobrar
                            y <span data-campo="carritos"></span> en compras que se están confirmando. Salen del depósito,
                            y aparecen acá, cuando se cobran.
                            @can('venta.ver')
                                <a href="{{ route('ventas.index', ['canal' => 'online', 'estado' => 'pendiente_pago']) }}">Ver
                                    los pedidos sin cobrar</a>
                            @endcan
                        </p>

                        <div class="mt-2" data-inactivo hidden>
                            <span class="badge text-bg-secondary">Producto inactivo</span>
                            <span class="small text-body-secondary">
                                No se ofrece a la venta, pero su stock se sigue registrando.
                            </span>
                        </div>
                    </div>

                    @can('stock.ajustar')
                        <a href="{{ route('stock.ajuste.create', $productoId) }}" class="btn btn-acento text-nowrap">
                            <i class="bi bi-clipboard-check" aria-hidden="true"></i> Ajustar inventario
                        </a>
                    @endcan
                </div>
            </div>
        @endif

        <noscript>
            <div class="alert alert-warning d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
                <div>
                    <strong>Esta pantalla necesita JavaScript.</strong>
                    Los movimientos se piden a la API con tu credencial, y sólo el JavaScript la manda.
                </div>
            </div>
        </noscript>

        {{-- Filtros por GET: la pantalla queda enlazable y el botón "atrás" funciona --}}
        <form method="GET" action="{{ route('stock.index') }}" class="card card-body border-0 shadow-sm mb-4" data-filtros-kardex>
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-4">
                    <label for="producto_id" class="form-label">Producto</label>
                    <select class="form-select" id="producto_id" name="producto_id" data-buscable data-buscable-diferido
                        data-buscable-url="/productos?forma=gestion"
                        data-buscable-minimo="2" data-buscable-detalle="disponible">
                        <option value="">Todos</option>
                        @if ($productoId)
                            <option value="{{ $productoId }}" selected>El elegido</option>
                        @endif
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="tipo" class="form-label">Tipo</label>
                    <select class="form-select" id="tipo" name="tipo">
                        <option value="">Todos</option>
                        @foreach (MovimientoStock::TIPOS as $valor => $texto)
                            <option value="{{ $valor }}" @selected(request('tipo') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="canal" class="form-label">Canal de la venta</label>
                    <select class="form-select" id="canal" name="canal">
                        <option value="">Todos</option>
                        @foreach (Venta::CANALES as $valor => $texto)
                            <option value="{{ $valor }}" @selected(request('canal') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Las opciones son quienes registraron algún movimiento: las pide el JS. --}}
                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="usuario_id" class="form-label">Lo registró</label>
                    <select class="form-select" id="usuario_id" name="usuario_id" data-buscable data-buscable-diferido
                        data-seleccionada="{{ request('usuario_id') }}">
                        <option value="">Cualquiera</option>
                    </select>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="desde" class="form-label">Desde</label>
                    <input type="date" class="form-control" id="desde" name="desde" value="{{ request('desde') }}"
                        aria-describedby="ayudaRango">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="hasta" class="form-label">Hasta</label>
                    <input type="date" class="form-control" id="hasta" name="hasta" value="{{ request('hasta') }}"
                        aria-describedby="ayudaRango">
                </div>

                <div class="col-12">
                    <div id="ayudaRango" class="form-text">
                        Las dos fechas se incluyen completas, de la primera hora a la última.
                    </div>
                </div>

                @php($chip = 'badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1')

                <div class="col-12 d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                    <div class="text-body-secondary small">
                        @if ($hayFiltros)
                            <div class="d-inline-flex align-items-center flex-wrap gap-1">
                                <a href="{{ route('stock.index') }}"
                                    class="badge rounded-pill text-bg-dark text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Hacé clic para limpiar todos los filtros">
                                    <i class="bi bi-funnel-fill text-white-50" aria-hidden="true"></i>
                                    <span>Filtros aplicados</span>
                                    <i class="bi bi-x-circle-fill text-white-50 ms-1" aria-hidden="true"></i>
                                </a>

                                {{-- El nombre del producto y de quién registró los pone el JS, con lo que trae la API. --}}
                                @if ($productoId)
                                    <a href="{{ request()->fullUrlWithoutQuery(['producto_id', 'pagina']) }}" class="{{ $chip }}" title="Quitar este filtro">
                                        Producto: <span data-nombre-filtro="producto_id">el elegido</span>
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @if (isset(MovimientoStock::TIPOS[request('tipo')]))
                                    <a href="{{ request()->fullUrlWithoutQuery(['tipo', 'pagina']) }}" class="{{ $chip }}" title="Quitar este filtro">
                                        Tipo: {{ MovimientoStock::TIPOS[request('tipo')] }}
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @if (request()->filled('usuario_id'))
                                    <a href="{{ request()->fullUrlWithoutQuery(['usuario_id', 'pagina']) }}" class="{{ $chip }}" title="Quitar este filtro">
                                        Registró: <span data-nombre-filtro="usuario_id">la cuenta elegida</span>
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @if (isset(Venta::CANALES[request('canal')]))
                                    <a href="{{ request()->fullUrlWithoutQuery(['canal', 'pagina']) }}" class="{{ $chip }}" title="Quitar este filtro">
                                        Canal: {{ Venta::CANALES[request('canal')] }}
                                        <i class="bi bi-x" aria-hidden="true"></i>
                                    </a>
                                @endif

                                @foreach (['desde' => 'Desde', 'hasta' => 'Hasta'] as $clave => $rotulo)
                                    @if (request()->filled($clave))
                                        <a href="{{ request()->fullUrlWithoutQuery([$clave, 'pagina']) }}" class="{{ $chip }}" title="Quitar este filtro">
                                            {{ $rotulo }}: {{ request($clave) }}
                                            <i class="bi bi-x" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <span class="text-body-secondary">Mostrando todos los movimientos</span>
                        @endif
                    </div>

                    <div class="d-flex gap-2">
                        @if ($hayFiltros)
                            <a href="{{ route('stock.index') }}"
                                class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                                <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                            </a>
                        @endif

                        <x-imprimir api="/stock/movimientos/impresion" :listado="new \App\Impresion\ListadoMovimientos()" />

                        <button type="submit" class="btn btn-sm btn-outline-dark d-inline-flex align-items-center gap-1">
                            <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Libro de movimientos de stock</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Fecha</th>
                            <th scope="col">Producto</th>
                            <th scope="col">Tipo</th>
                            <th scope="col" class="text-end">Cantidad</th>
                            <th scope="col" class="text-end">Stock</th>
                            <th scope="col" class="d-none d-lg-table-cell">Motivo u origen</th>
                            <th scope="col" class="d-none d-md-table-cell">Lo registró</th>
                        </tr>
                    </thead>
                    <tbody data-listado-filas data-columnas="7" data-ver-todos="Ver todos"
                        data-vacio="Todavía no hay movimientos registrados. El primero aparece acá cuando se ajuste un inventario o se reciba una compra."
                        data-vacio-filtrado="Ningún movimiento coincide con el filtro.">
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- El signo es texto, no color: ningún dato se comunica sólo con color. --}}
            <template data-listado-fila>
                <tr>
                    <td class="text-nowrap">
                        <span data-campo="dia"></span>
                        <div class="small text-body-secondary" data-campo="hora"></div>
                    </td>
                    <td>
                        <span class="fw-semibold" data-campo="producto"></span>
                        <div class="small text-body-secondary" data-campo="codigo"></div>
                    </td>
                    <td><span class="badge text-bg-light border" data-campo="tipo_texto"></span></td>
                    <td class="text-end text-nowrap fw-semibold" data-campo="cantidad"></td>
                    <td class="text-end text-nowrap">
                        <span aria-hidden="true">
                            <span class="text-body-secondary" data-campo="stock_anterior"></span>
                            →
                            <span class="fw-semibold" data-campo="stock_resultante"></span>
                        </span>
                        <span class="visually-hidden" data-campo="stock_leido"></span>
                    </td>
                    <td class="d-none d-lg-table-cell small">
                        <span data-campo="motivo"></span>
                        <div class="text-body-secondary" data-campo="remito_texto"></div>
                    </td>
                    <td class="d-none d-md-table-cell small" data-campo="registrado_por"></td>
                </tr>
            </template>

            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2" data-listado-pie hidden>
                <p class="text-body-secondary small mb-0" data-listado-resumen></p>
                <div data-listado-paginas></div>
            </div>
        </div>
    </div>
@endsection
