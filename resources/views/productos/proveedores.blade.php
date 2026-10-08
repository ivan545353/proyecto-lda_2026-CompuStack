@extends('layouts.app')

@use('App\Support\Importe')

@section('title', 'Proveedores de ' . $producto->nombre)

@section('content')
    <a href="{{ route('productos.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al catálogo
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Proveedores del producto</h1>
        <p class="text-body-secondary small mb-0">
            A quién se le puede comprar, a qué precio, y a cuál le pide la reposición automática.
        </p>
    </div>

    @include('partials.errores')

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-1">{{ $producto->nombre }}</h2>
            <p class="text-body-secondary small mb-0">
                Código {{ $producto->codigo }} · {{ $producto->categoria->ruta }}@if ($producto->marca)
                    · {{ $producto->marca->nombre }}
                @endif
            </p>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <caption class="visually-hidden">
                    Proveedores de {{ $producto->nombre }} con su último costo, ordenados del más barato al más caro
                </caption>
                <thead class="table-light">
                    <tr>
                        <th scope="col">Proveedor</th>
                        <th scope="col" class="text-end">Último costo</th>
                        <th scope="col" class="d-none d-md-table-cell">Estado</th>
                        <th scope="col" class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vinculos as $vinculo)
                        @php
                            $costo = $vinculo->pivot->costo_ultimo;
                            $esBarato = $masBarato !== null && $masBarato->id === $vinculo->id;
                            $referencia = $masBarato?->pivot->costo_ultimo;
                            // Cuánto más caro que el más barato. Es lo que convierte
                            // una lista de precios en una comparación.
                            $diferencia =
                                $costo !== null && $referencia !== null && (float) $referencia > 0 && !$esBarato
                                    ? round(((float) $costo / (float) $referencia - 1) * 100)
                                    : null;
                            $pedido = $pedidos[$vinculo->id] ?? null;
                        @endphp
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $vinculo->razon_social }}</span>
                                <div class="small text-body-secondary">
                                    @if ($vinculo->pivot->codigo_proveedor)
                                        Su código: {{ $vinculo->pivot->codigo_proveedor }}
                                    @else
                                        Sin código del proveedor
                                    @endif
                                    @if ($vinculo->plazo_entrega_dias)
                                        · entrega en {{ $vinculo->plazo_entrega_dias }} días
                                    @endif
                                </div>
                                {{-- En móvil la columna de estado no se ve: los badges
                                     se muestran acá para no perder la información. --}}
                                <div class="d-md-none mt-1">
                                    @if ($vinculo->pivot->es_preferido)
                                        <span class="badge text-bg-dark">Le pedimos a este</span>
                                    @endif
                                    @if ($esBarato)
                                        <span class="badge text-bg-success">Más barato</span>
                                    @endif
                                </div>
                            </td>

                            <td class="text-end text-nowrap">
                                @if ($costo === null)
                                    <span class="text-body-secondary">Sin cargar</span>
                                @else
                                    {{ Importe::pesos($costo) }}
                                    @if ($diferencia !== null && $diferencia > 0)
                                        <div class="small text-body-secondary">
                                            +{{ $diferencia }} % que el más barato
                                        </div>
                                    @endif
                                @endif
                            </td>

                            <td class="d-none d-md-table-cell">
                                {{-- Ningún estado se comunica sólo con color: el badge
                                     lleva texto. --}}
                                @if ($vinculo->pivot->es_preferido)
                                    <span class="badge text-bg-dark">Le pedimos a este</span>
                                @endif
                                @if ($esBarato)
                                    <span class="badge text-bg-success">Más barato</span>
                                @endif
                                @unless ($vinculo->activo)
                                    <span class="badge text-bg-secondary">Proveedor inactivo</span>
                                @endunless
                            </td>

                            <td class="text-end text-nowrap">
                                @unless ($vinculo->pivot->es_preferido)
                                    <form method="POST" class="d-inline"
                                        action="{{ route('producto-proveedores.preferido', [$producto, $vinculo]) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm btn-outline-dark"
                                            aria-label="Pedirle la reposición de {{ $producto->nombre }} a {{ $vinculo->razon_social }}">
                                            <i class="bi bi-hand-index-thumb" aria-hidden="true"></i>
                                            <span class="d-none d-lg-inline">Pedirle a este</span>
                                        </button>
                                    </form>
                                @endunless

                                <a href="{{ route('producto-proveedores.edit', [$producto, $vinculo]) }}"
                                    class="btn btn-sm btn-outline-dark"
                                    aria-label="Editar el vínculo con {{ $vinculo->razon_social }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                    <span class="d-none d-lg-inline">Editar</span>
                                </a>

                                @if ($pedido)
                                    {{-- No se ofrece el camino que el sistema va a negar
                                         (prevención de errores). El botón se reemplaza por
                                         el motivo, en lugar de desaparecer sin explicación. --}}
                                    <span class="badge text-bg-light border text-body-secondary text-wrap">
                                        No se puede quitar: {{ $pedido->numeroFormateado() }}
                                        {{ mb_strtolower($pedido->estadoTexto()) }}
                                    </span>
                                @else
                                    <form method="POST" class="d-inline"
                                        action="{{ route('producto-proveedores.destroy', [$producto, $vinculo]) }}"
                                        onsubmit="return confirm(@js($vinculo->pivot->es_preferido && $vinculos->count() > 1 ? "¿Quitar a {$vinculo->razon_social} de «{$producto->nombre}»? Es el elegido: el más antiguo de los que quedan va a pasar a serlo." : "¿Quitar a {$vinculo->razon_social} de «{$producto->nombre}»?"));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"
                                            aria-label="Quitar a {{ $vinculo->razon_social }} de {{ $producto->nombre }}">
                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">
                                Todavía no se le compra a nadie. Mientras no haya ninguno, la reposición
                                automática no va a poder pedir este producto.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-body border-0 shadow-sm">
        <h2 class="h5 mb-1">Agregar un proveedor</h2>

        @if ($disponibles->isEmpty())
            <p class="text-body-secondary small mb-0">
                @if ($vinculos->isEmpty())
                    No hay ningún proveedor activo para cargar.
                @else
                    Ya están cargados todos los proveedores activos.
                @endif
                @can('proveedor.crear')
                    Para sumar otro, <a href="{{ route('proveedores.create') }}">dalo de alta primero</a>.
                @endcan
            </p>
        @else
            <p class="text-body-secondary small">
                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
            </p>

            @include('productos._proveedor', [
                'producto' => $producto,
                'vinculo' => null,
                'disponibles' => $disponibles,
                'esElPrimero' => $vinculos->isEmpty(),
            ])
        @endif
    </div>
@endsection
