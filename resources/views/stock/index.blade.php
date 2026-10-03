@extends('layouts.app')

@section('title', 'Movimientos de stock')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Movimientos de stock</h1>
        <p class="text-body-secondary small mb-0">
            Todo lo que entró y salió del depósito, con quién lo registró y por qué.
        </p>
    </div>

    {{-- Cuando el kardex está filtrado por un producto, su situación de stock y el
         acceso al ajuste van acá: el usuario decide con el número a la vista. --}}
    @if ($producto)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="h5 mb-1">{{ $producto->nombre }}</h2>
                    <p class="text-body-secondary small mb-2">Código {{ $producto->codigo }}</p>

                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong>{{ $producto->stock }}</strong> en depósito</span>
                        <span class="text-body-secondary">{{ $producto->stock_reservado }} reservadas</span>
                        <span><strong>{{ $producto->stock_disponible }}</strong> disponibles</span>
                        <span class="text-body-secondary">mínimo {{ $producto->stock_minimo }}</span>
                    </div>

                    @if (!$producto->activo)
                        <div class="mt-2">
                            <span class="badge text-bg-secondary">Producto inactivo</span>
                            <span class="small text-body-secondary">
                                No se ofrece a la venta, pero su stock se sigue registrando.
                            </span>
                        </div>
                    @endif
                </div>

                @can('stock.ajustar')
                    <a href="{{ route('stock.ajuste.create', $producto) }}" class="btn btn-acento text-nowrap">
                        <i class="bi bi-clipboard-check" aria-hidden="true"></i> Ajustar inventario
                    </a>
                @endcan
            </div>
        </div>
    @endif

    @php
        $hayFiltros = request()->hasAny(['producto_id', 'tipo', 'usuario_id', 'desde', 'hasta']);
        $usuarioFiltrado = $usuarios->firstWhere('id', request()->integer('usuario_id'));

        $chips = collect([
            'producto_id' => $producto ? 'Producto: ' . Str::limit($producto->nombre, 24) : null,
            'tipo' => request('tipo')
                ? 'Tipo: ' . (App\Models\MovimientoStock::TIPOS[request('tipo')] ?? request('tipo'))
                : null,
            'usuario_id' => $usuarioFiltrado
                ? "Registró: {$usuarioFiltrado->nombre} {$usuarioFiltrado->apellido}"
                : null,
            'desde' => request('desde') ? 'Desde: ' . request('desde') : null,
            'hasta' => request('hasta') ? 'Hasta: ' . request('hasta') : null,
        ])->filter();
    @endphp

    {{-- Filtros por GET: la pantalla queda enlazable y el botón "atrás" funciona --}}
    <form method="GET" action="{{ route('stock.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="producto_id" class="form-label">Producto</label>
                <select class="form-select" id="producto_id" name="producto_id" data-buscable>
                    <option value="">Todos</option>
                    @foreach ($productos as $opcion)
                        <option value="{{ $opcion->id }}" @selected(request()->integer('producto_id') === $opcion->id)>
                            {{ $opcion->codigo }} — {{ $opcion->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <label for="tipo" class="form-label">Tipo</label>
                <select class="form-select" id="tipo" name="tipo">
                    <option value="">Todos</option>
                    @foreach (App\Models\MovimientoStock::TIPOS as $valor => $texto)
                        <option value="{{ $valor }}" @selected(request('tipo') === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-5">
                <label for="usuario_id" class="form-label">Lo registró</label>
                <select class="form-select" id="usuario_id" name="usuario_id" data-buscable>
                    <option value="">Cualquiera</option>
                    @foreach ($usuarios as $opcion)
                        <option value="{{ $opcion->id }}" @selected(request()->integer('usuario_id') === $opcion->id)>
                            {{ $opcion->apellido }}, {{ $opcion->nombre }}
                        </option>
                    @endforeach
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

                            @foreach ($chips as $parametro => $texto)
                                <a href="{{ request()->fullUrlWithoutQuery([$parametro, 'page']) }}"
                                    class="badge rounded-pill bg-body-tertiary text-dark border text-decoration-none px-2 py-1 d-inline-flex align-items-center gap-1"
                                    title="Quitar este filtro">
                                    {{ $texto }}
                                    <i class="bi bi-x" aria-hidden="true"></i>
                                </a>
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
                        <th scope="col" class="text-end">Stock resultante</th>
                        <th scope="col" class="d-none d-lg-table-cell">Motivo u origen</th>
                        <th scope="col" class="d-none d-md-table-cell">Lo registró</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movimientos as $movimiento)
                        <tr>
                            <td class="text-nowrap">
                                {{ $movimiento->created_at->format('d/m/Y') }}
                                <div class="small text-body-secondary">{{ $movimiento->created_at->format('H:i') }}</div>
                            </td>

                            <td>
                                <span class="fw-semibold">{{ $movimiento->producto->nombre }}</span>
                                <div class="small text-body-secondary">{{ $movimiento->producto->codigo }}</div>
                            </td>

                            <td>
                                <span class="badge text-bg-light border">{{ $movimiento->tipoTexto() }}</span>
                            </td>

                            {{-- El signo es texto, no color: ningún dato se comunica sólo con color --}}
                            <td
                                class="text-end text-nowrap fw-semibold {{ $movimiento->esIngreso() ? 'text-success-emphasis' : 'text-danger-emphasis' }}">
                                {{ $movimiento->esIngreso() ? '+' : '' }}{{ $movimiento->cantidad }}
                            </td>

                            <td class="text-end">{{ $movimiento->stock_resultante }}</td>

                            <td class="d-none d-lg-table-cell small">
                                {{ $movimiento->motivo ?? ($movimiento->origenTexto() ?? '—') }}
                            </td>

                            <td class="d-none d-md-table-cell small">
                                @if ($movimiento->usuario)
                                    {{ $movimiento->usuario->nombre }} {{ $movimiento->usuario->apellido }}
                                @else
                                    <span class="text-body-secondary">Generado por el sistema</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">
                                @if ($hayFiltros)
                                    Ningún movimiento coincide con el filtro.
                                    <a href="{{ route('stock.index') }}">Ver todos</a>.
                                @else
                                    Todavía no hay movimientos registrados. El primero aparece acá
                                    cuando se ajuste un inventario o se reciba una compra.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($movimientos->total() > 0)
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0">
                Mostrando {{ $movimientos->firstItem() }}–{{ $movimientos->lastItem() }} de {{ $movimientos->total() }}.
            </p>

            {{ $movimientos->links() }}
        </div>
    @endif
@endsection
