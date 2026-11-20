@extends('layouts.app')

@section('title', 'Proveedores del producto')

{{-- Shell (tramo D, regla 37): el producto, los vínculos y los proveedores para agregar llegan por
     la API. La fila es una plantilla; el texto lo pone la pantalla. --}}
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

    <div data-pantalla="producto-proveedores" data-producto="{{ $productoId }}">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-1" data-dato="nombre">Cargando el producto…</h2>
                <p class="text-body-secondary small mb-0" data-dato="detalle"></p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">
                        Proveedores del producto con su último costo, ordenados del más barato al más caro
                    </caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Proveedor</th>
                            <th scope="col" class="text-end">Último costo</th>
                            <th scope="col" class="d-none d-md-table-cell">Estado</th>
                            <th scope="col" class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-vinculos aria-live="polite">
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">Cargando…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <template data-vinculo>
            <tr>
                <td>
                    <span class="fw-semibold" data-campo="razon_social"></span>
                    <div class="small text-body-secondary" data-campo="detalle"></div>
                    {{-- En móvil la columna de estado no se ve: los badges se muestran acá. --}}
                    <div class="d-md-none mt-1">
                        <span class="badge text-bg-dark" data-marca="preferido" hidden>Le pedimos a este</span>
                        <span class="badge text-bg-success" data-marca="barato" hidden>Más barato</span>
                    </div>
                </td>
                <td class="text-end text-nowrap">
                    <span data-campo="costo"></span>
                    <div class="small text-body-secondary" data-campo="diferencia" hidden></div>
                </td>
                <td class="d-none d-md-table-cell">
                    {{-- Ningún estado se comunica sólo con color: el badge lleva texto. --}}
                    <span class="badge text-bg-dark" data-marca="preferido" hidden>Le pedimos a este</span>
                    <span class="badge text-bg-success" data-marca="barato" hidden>Más barato</span>
                    <span class="badge text-bg-secondary" data-marca="inactivo" hidden>Proveedor inactivo</span>
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-dark" data-accion="preferido">
                        <i class="bi bi-hand-index-thumb" aria-hidden="true"></i>
                        <span class="d-none d-lg-inline">Pedirle a este</span>
                    </button>
                    <a href="{{ route('producto-proveedores.edit', [$productoId, '__PROVEEDOR__']) }}"
                        class="btn btn-sm btn-outline-dark" data-accion="editar">
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                        <span class="d-none d-lg-inline">Editar</span>
                    </a>
                    {{-- No se ofrece el camino que el sistema va a negar: con un pedido en curso, el
                         botón se reemplaza por el motivo. --}}
                    <span class="badge text-bg-light border text-body-secondary text-wrap" data-campo="bloqueo" hidden></span>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-accion="quitar">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </td>
            </tr>
        </template>

        <div class="card card-body border-0 shadow-sm">
            <h2 class="h5 mb-1">Agregar un proveedor</h2>

            <p class="text-body-secondary small mb-0" data-sin-disponibles hidden>
                <span data-campo="sin-disponibles"></span>
                @can('proveedor.crear')
                    Para sumar otro, <a href="{{ route('proveedores.create') }}">dalo de alta primero</a>.
                @endcan
            </p>

            <div data-con-disponibles hidden>
                <p class="text-body-secondary small">
                    Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
                </p>

                @include('productos._proveedor', ['esEdicion' => false])
            </div>
        </div>
    </div>
@endsection
