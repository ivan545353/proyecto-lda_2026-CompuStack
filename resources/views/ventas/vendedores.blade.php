@use('App\Support\Importe')

@extends('layouts.app')

@section('title', 'Ranking de vendedores')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Ranking de vendedores</h1>
            <p class="text-body-secondary small mb-0">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}. Quién vendió, por lo facturado.
            </p>
        </div>

        <a href="{{ route('panel.general', request()->only(['desde', 'hasta'])) }}" class="btn btn-outline-dark text-nowrap">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al panel
        </a>
    </div>

    @include('panel._periodo', ['ruta' => 'ventas.vendedores'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Vendedores del período ordenados por lo facturado</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-end d-none d-md-table-cell">Puesto</th>
                        <th scope="col">Vendedor</th>
                        <th scope="col" class="text-end">Ventas</th>
                        <th scope="col" class="text-end">Facturado</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Ticket promedio</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $fila)
                        <tr>
                            <td class="text-end d-none d-md-table-cell">{{ $filas->firstItem() + $loop->index }}</td>
                            <td>{{ $fila->apellido }}, {{ $fila->nombre }}</td>
                            <td class="text-end">{{ $fila->cantidad }}</td>
                            <td class="text-end">{{ Importe::pesos($fila->facturado) }}</td>
                            <td class="text-end d-none d-md-table-cell">{{ Importe::pesos($fila->ticket) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4" data-vacio>
                                @if ($elegido)
                                    Ninguna venta cobrada entre esas fechas. Probá con otro período.
                                @else
                                    Todavía no hay ventas cobradas este mes.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                {{-- La misma consulta sin agrupar: suma lo que listan todas las páginas. --}}
                @if ($filas->total() > 0)
                    <tfoot class="table-light" data-total>
                        <tr>
                            <th scope="row" colspan="2" class="d-none d-md-table-cell">Todos los vendedores</th>
                            <th scope="row" class="d-md-none">Todos</th>
                            <td class="text-end fw-semibold">{{ $total->cantidad }}</td>
                            <td class="text-end fw-semibold">{{ Importe::pesos($total->facturado) }}</td>
                            <td class="text-end fw-semibold d-none d-md-table-cell">{{ Importe::pesos($total->ticket) }}
                            </td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if ($filas->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0 text-center text-sm-start">
                Mostrando {{ $filas->firstItem() }}–{{ $filas->lastItem() }} de {{ $filas->total() }} vendedores.
            </p>

            <div class="d-flex justify-content-center">
                {{ $filas->links() }}
            </div>
        </div>
    @endif
@endsection
