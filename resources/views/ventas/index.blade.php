@extends('layouts.app')

@use('App\Support\Importe')

@section('title', 'Ventas')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Ventas</h1>
            <p class="text-body-secondary small mb-0">
                Un presupuesto no descuenta stock ni genera comprobante: se convierte en venta
                cuando se cobra, y ahí sale la mercadería del depósito.
            </p>
        </div>

        @can('venta.crear')
            <a href="{{ route('ventas.create') }}"
                class="btn btn-acento text-nowrap align-self-stretch align-self-sm-auto d-inline-flex align-items-center justify-content-center gap-1">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva venta
            </a>
        @endcan
    </div>

    @php
        $hayFiltros = request()->hasAny(['q', 'estado', 'cliente_id', 'vendedor_id', 'desde', 'hasta']);

        $chips = collect([
            'q' => request('q') ? 'Texto: "' . Str::limit(request('q'), 18) . '"' : null,
            'estado' => request('estado')
                ? 'Estado: ' . (App\Models\Venta::ESTADOS[request('estado')] ?? request('estado'))
                : null,
            'cliente_id' => $clienteFiltrado ? 'Cliente: ' . Str::limit($clienteFiltrado->razon_social, 22) : null,
            'vendedor_id' => $vendedorFiltrado ? 'Vendedor: ' . $vendedorFiltrado->nombre_completo : null,
            'desde' => request('desde') ? 'Desde: ' . request('desde') : null,
            'hasta' => request('hasta') ? 'Hasta: ' . request('hasta') : null,
        ])->filter();
    @endphp

    <form method="GET" action="{{ route('ventas.index') }}" class="card card-body border-0 shadow-sm mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-4">
                <label for="q" class="form-label">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-body-tertiary border-end-0 text-body-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </span>
                    <input type="search" class="form-control border-start-0 ps-1" id="q" name="q"
                        value="{{ request('q') }}" placeholder="V-00042 o nombre del cliente">
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="estado" class="form-label">Estado</label>
                <select class="form-select" id="estado" name="estado">
                    <option value="">Todos</option>
                    {{-- Sólo los estados que la Etapa 1 produce. Los siete de la
                         tienda online están declarados en el ENUM pero ofrecerlos
                         acá sería ofrecer filtros que siempre devuelven vacío. --}}
                    @foreach (App\Models\Venta::ESTADOS_EN_USO as $valor)
                        <option value="{{ $valor }}" @selected(request('estado') === $valor)>
                            {{ App\Models\Venta::ESTADOS[$valor] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <label for="vendedor_id" class="form-label">Vendedor</label>
                <select class="form-select" id="vendedor_id" name="vendedor_id" data-buscable>
                    <option value="">Todos</option>
                    @foreach ($vendedores as $vendedor)
                        <option value="{{ $vendedor->id }}" @selected(request()->integer('vendedor_id') === $vendedor->id)>
                            {{ $vendedor->nombre_completo }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-sm-6">
                <label for="desde" class="form-label">Emitida desde</label>
                <input type="date" class="form-control" id="desde" name="desde" value="{{ request('desde') }}">
            </div>

            <div class="col-12 col-sm-6">
                <label for="hasta" class="form-label">Emitida hasta</label>
                <input type="date" class="form-control" id="hasta" name="hasta" value="{{ request('hasta') }}">
            </div>

            {{-- El filtro por cliente llega por enlace, desde la ficha del cliente.
                 No tiene campo acá, pero sí su chip, para que se pueda quitar. --}}
            @if ($clienteFiltrado)
                <input type="hidden" name="cliente_id" value="{{ $clienteFiltrado->id }}">
            @endif

            <div
                class="col-12 d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                <div class="text-body-secondary small">
                    @if ($hayFiltros)
                        <div class="d-inline-flex align-items-center flex-wrap gap-1">
                            <a href="{{ route('ventas.index') }}"
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
                        <span class="text-body-secondary">Mostrando todas las ventas</span>
                    @endif
                </div>

                <div class="d-flex gap-2 align-self-stretch align-self-sm-auto justify-content-sm-end">
                    @if ($hayFiltros)
                        <a href="{{ route('ventas.index') }}"
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Limpiar
                        </a>
                    @endif

                    <button type="submit"
                        class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1 flex-fill flex-sm-grow-0">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrar
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="min-width: 40rem;">
                <caption class="visually-hidden">Listado de ventas y presupuestos</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="min-width: 7.5rem;">Venta</th>
                        <th scope="col" style="min-width: 12rem;">Cliente</th>
                        <th scope="col" class="d-none d-md-table-cell" style="min-width: 10rem;">Vendedor</th>
                        <th scope="col" style="min-width: 7rem;">Estado</th>
                        <th scope="col" class="text-center d-none d-lg-table-cell" style="min-width: 5.5rem;">
                            Productos
                        </th>
                        <th scope="col" class="text-end" style="min-width: 8rem;">Total</th>
                        <th scope="col" class="text-end" style="min-width: 5rem;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ventas as $venta)
                        <tr>
                            <td class="text-nowrap">

                                <a href="{{ route('ventas.show', $venta) }}" class="fw-semibold link-dark">
                                    {{ $venta->numeroFormateado() }}
                                </a>
                                <div class="small text-body-secondary">
                                    {{ $venta->created_at->format('d/m/Y') }}
                                </div>
                            </td>

                            <td>
                                {{ $venta->clienteTexto() }}
                            </td>

                            <td class="d-none d-md-table-cell">
                                {{ $venta->usuario?->nombre_completo ?? '—' }}
                            </td>

                            <td>
                                <span class="badge {{ $venta->estadoClase() }}">{{ $venta->estadoTexto() }}</span>
                            </td>

                            <td class="text-center d-none d-lg-table-cell">{{ $venta->lineas_count }}</td>

                            <td class="text-end text-nowrap">{{ Importe::pesos($venta->total) }}</td>

                            <td class="text-end text-nowrap">
                                <a href="{{ route('ventas.show', $venta) }}" class="btn btn-sm btn-outline-dark"
                                    aria-label="Ver la venta {{ $venta->numeroFormateado() }}">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                    <span class="d-none d-sm-inline">Ver</span>
                                </a>

                                {{-- Sólo un presupuesto se edita. No ofrecer el botón
                                     es la mitad del trabajo; la otra la hacen el
                                     controlador y el servicio. --}}
                                @if ($venta->esPresupuesto())
                                    @can('venta.editar')
                                        <a href="{{ route('ventas.edit', $venta) }}"
                                            class="btn btn-sm btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1"
                                            aria-label="Editar la venta {{ $venta->numeroFormateado() }}">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                            <span class="d-none d-sm-inline">Editar</span>
                                        </a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">
                                @if ($hayFiltros)
                                    Ninguna venta coincide con el filtro.
                                    <a href="{{ route('ventas.index') }}">Ver todas</a>.
                                @else
                                    Todavía no hay ventas registradas.
                                    @can('venta.crear')
                                        <a href="{{ route('ventas.create') }}">Cargar la primera</a>.
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($ventas->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0 text-center text-sm-start">
                Mostrando {{ $ventas->firstItem() }}–{{ $ventas->lastItem() }} de {{ $ventas->total() }}.
            </p>

            <div class="d-flex justify-content-center">
                {{ $ventas->links() }}
            </div>
        </div>
    @endif
@endsection
