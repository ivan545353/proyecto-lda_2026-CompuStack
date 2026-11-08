@use('App\Support\Importe')

@extends('layouts.app')

@section('title', 'Productos más vendidos')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Productos más vendidos</h1>
            <p class="text-body-secondary small mb-0">
                Del {{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}. Por unidades, descontando lo devuelto.
            </p>
        </div>

        <a href="{{ route('panel.general', request()->only(['desde', 'hasta'])) }}" class="btn btn-outline-dark text-nowrap">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al panel
        </a>
    </div>

    @include('panel._periodo', ['ruta' => 'ventas.productos'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">Productos más vendidos del período, por unidades</caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col" class="text-end d-none d-md-table-cell">Puesto</th>
                        <th scope="col">Producto</th>
                        <th scope="col" class="text-end">Unidades</th>
                        <th scope="col" class="text-end d-none d-md-table-cell">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($filas as $fila)
                        <tr>
                            <td class="text-end d-none d-md-table-cell">{{ $filas->firstItem() + $loop->index }}</td>
                            <td>
                                <span class="fw-semibold">{{ $fila->nombre }}</span>
                                <div class="small text-body-secondary">{{ $fila->codigo }}</div>
                            </td>
                            <td class="text-end">{{ $fila->unidades }}</td>
                            <td class="text-end d-none d-md-table-cell">{{ Importe::pesos($fila->importe) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4" data-vacio>
                                @if ($elegido)
                                    Ningún producto vendido entre esas fechas, o volvió todo. Probá con otro período.
                                @else
                                    Todavía no hay productos vendidos este mes.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($filas->total() > 0)
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center flex-wrap gap-2 mt-3">
            <p class="text-body-secondary small mb-0 text-center text-sm-start">
                Mostrando {{ $filas->firstItem() }}–{{ $filas->lastItem() }} de {{ $filas->total() }} productos.
            </p>

            <div class="d-flex justify-content-center">
                {{ $filas->links() }}
            </div>
        </div>
    @endif
@endsection
