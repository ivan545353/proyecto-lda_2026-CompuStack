@extends('layouts.app')

@use('App\Support\Importe')

{{-- Shell (tramo H, regla 37): en la edición, el cliente, los renglones y sus precios llegan por
     `GET /api/v1/ventas/{venta}`; emitir es `POST /api/v1/ventas` y guardar, `PATCH`. Lo único que trae
     el servidor es lo que no es de negocio: el tope de descuento del usuario y si hay algo que vender. --}}
@php
    $esAlta = !$venta->exists;
@endphp

@section('title', $esAlta ? 'Nueva venta' : 'Editar la venta')

@section('content')
    <a href="{{ $esAlta ? route('ventas.index') : route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        {{ $esAlta ? 'Volver al listado' : 'Volver a la venta' }}
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">@if ($esAlta) Nueva venta @else Editar <span data-numero-venta>la venta</span> @endif</h1>
        <p class="text-body-secondary small mb-0">
            @if ($esAlta)
                Se emite como <strong>presupuesto</strong>: no descuenta stock ni genera comprobante hasta que se cobre.
            @else
                Las líneas cargadas <strong>conservan su precio original</strong>. Cualquier producto que agregues ahora se
                cotiza al valor actual.
            @endif
        </p>
    </div>

    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Esta pantalla necesita JavaScript.</strong>
                Desde la Etapa 3 la venta se carga y se guarda por la API, con tu credencial, y
                sólo el JavaScript la manda (HL-111): sin él no se puede emitir ni guardar.
                @can('cliente.crear')
                    «Nuevo cliente» te lleva a la pantalla de clientes en lugar de abrir una ventana,
                    y lo que no guardaste acá se pierde.
                @endcan
            </div>
        </div>
    </noscript>

    @if (!$hayProductos)
        <div class="card card-body border-0 shadow-sm">
            <p class="mb-0">
                No hay ningún producto activo en el catálogo, así que no hay nada que vender.
                @can('producto.ver')
                    Cargá al menos uno desde el <a href="{{ route('productos.index') }}">catálogo</a>.
                @endcan
            </p>
        </div>
    @else
        <div data-pantalla="formulario-venta" data-venta="{{ $esAlta ? '' : $venta->id }}"
            data-ficha="{{ route('ventas.show', '__ID__') }}" @unless ($esAlta) aria-busy="true" @endunless>
        @unless ($esAlta)
            <p class="text-body-secondary" data-cargando>Cargando la venta…</p>
        @endunless

        <form novalidate data-formulario-venta @unless ($esAlta) hidden @endunless>
            <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Datos de la venta</h2>

                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                <label for="cliente_id" class="form-label mb-0">Cliente</label>

                                {{-- Sin JavaScript es un enlace a clientes/crear; con JavaScript,
                                     Bootstrap abre la ventana y cancela la navegación. --}}
                                @can('cliente.crear')
                                    <a href="{{ route('clientes.create') }}" class="btn btn-sm btn-outline-dark"
                                        data-bs-toggle="modal" data-bs-target="#nuevoCliente" data-alta-rapida-abre>
                                        <i class="bi bi-person-plus" aria-hidden="true"></i> Nuevo cliente
                                    </a>
                                @endcan
                            </div>
                            <select class="form-select" id="cliente_id"
                                name="cliente_id" aria-describedby="ayudaCliente" data-buscable
                                data-buscable-url="/clientes"
                                data-buscable-minimo="2">
                                <option value="">Sin identificar</option>
                            </select>

                            <div id="ayudaCliente" class="form-text">
                                Opcional en ventas de mostrador. Buscalo por nombre o por documento.
                            </div>
                            @can('cliente.crear')
                                <div id="anuncioCliente" class="form-text text-success-emphasis" aria-live="polite"></div>
                            @endcan
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label for="descuento_porcentaje" class="form-label">Descuento</label>
                            <div class="input-group">
                                <input type="text" inputmode="decimal" autocomplete="off"
                                    class="form-control text-end"
                                    id="descuento_porcentaje" name="descuento_porcentaje" value=""
                                    placeholder="0" aria-describedby="ayudaDescuento">
                                <span class="input-group-text" aria-hidden="true">%</span>
                            </div>

                            <div id="ayudaDescuento" class="form-text">
                                Tope permitido: {{ Importe::paraFormulario($tope) }}%.
                            </div>
                        </div>

                        <div class="col-12 col-lg-3">
                            <label for="observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="observaciones" name="observaciones"
                                rows="2" maxlength="255" placeholder="Pasa a buscarlo el jueves"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4" data-venta>
                <div class="card-body">
                    <div
                        class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h5 mb-0">Productos</h2>
                            <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                                data-bs-toggle="tooltip" data-bs-placement="top"
                                data-bs-title="El precio se toma del catálogo y queda congelado para esta venta."
                                aria-label="Información sobre precios de los productos">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                            </button>
                        </div>

                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-7">
                            <label for="buscadorProducto" class="form-label">Agregar un producto</label>
                            <select id="buscadorProducto" data-buscador-producto data-buscable
                                data-buscable-url="/productos?forma=gestion&amp;estado=activos"
                                data-buscable-minimo="2"
                                data-buscable-detalle="precio,disponible" data-buscable-excluir=""
                                aria-describedby="ayudaBuscadorProducto">
                                <option value="">Buscá por código o nombre</option>
                            </select>

                            <div id="ayudaBuscadorProducto" class="form-text">
                                Ej: TEC-00123 o «teclado». Un código completo se agrega con Enter.
                            </div>
                        </div>
                    </div>

                    <table class="table align-middle mb-0" style="min-width: 44rem;">
                        <caption class="visually-hidden">Productos de la venta</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 15rem;">Producto</th>
                                <th scope="col" class="text-end" style="width: 7rem; min-width: 5.5rem;">Cantidad
                                </th>
                                <th scope="col" class="text-end" style="width: 9.5rem; min-width: 8rem;">Precio
                                    unitario</th>
                                <th scope="col" class="text-end" style="width: 8rem; min-width: 6.5rem;">Subtotal
                                </th>
                                <th scope="col" style="width: 3.5rem; min-width: 3rem;">
                                    <span class="visually-hidden">Quitar</span>
                                </th>
                            </tr>
                        </thead>

                        <tbody data-lineas>
                            <template id="plantillaLineaVenta">
                                @include('ventas._linea')
                            </template>
                        </tbody>

                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-body-secondary">Subtotal</td>
                                <td class="text-end text-nowrap" data-subtotal-venta>{{ Importe::pesos(0) }}</td>
                                <td></td>
                            </tr>

                            <tr data-fila-descuento hidden>
                                <td colspan="3" class="text-end text-body-secondary">Descuento</td>
                                <td class="text-end text-nowrap" data-descuento-venta>− {{ Importe::pesos(0) }}</td>
                                <td></td>
                            </tr>

                            <tr>
                                <td colspan="3" class="text-end fw-semibold">Total</td>
                                <td class="text-end fw-semibold text-nowrap" data-total-venta>{{ Importe::pesos(0) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>

                    <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas>
                        Todavía no hay ningún producto. Buscalo arriba por código o por nombre.
                    </p>

                </div>
            </div>

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                <a href="{{ $esAlta ? route('ventas.index') : route('ventas.show', $venta) }}"
                    class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-acento px-4">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    {{ $esAlta ? 'Emitir presupuesto' : 'Guardar cambios' }}
                </button>
            </div>
        </form>
        </div>

        {{-- Afuera del formulario de la venta: un <form> no puede contener otro. Envía al
             mismo endpoint que la pantalla de alta (POST /clientes de la API), así que valida con lo mismo. --}}
        @can('cliente.crear')
            <div class="modal fade" id="nuevoCliente" tabindex="-1" aria-labelledby="nuevoClienteTitulo"
                aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <form method="POST" novalidate class="modal-content" data-alta-rapida="#cliente_id"
                        data-alta-rapida-api="/clientes" data-alta-rapida-anuncio="#anuncioCliente">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="nuevoClienteTitulo">Nuevo cliente</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                aria-label="Cerrar sin crear el cliente"></button>
                        </div>

                        <div class="modal-body">
                            <p class="text-body-secondary small">
                                Queda elegido en esta venta, y los productos cargados no se pierden.
                                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
                            </p>

                            <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

                            @include('clientes._campos', [
                                'cliente' => new App\Models\Cliente([
                                    'condicion_iva' => 'consumidor_final',
                                    'tipo_doc' => 'dni',
                                ]),
                                'prefijo' => 'nuevoCliente_',
                                'autofocus' => false,
                            ])
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary"
                                data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-acento px-4">
                                <i class="bi bi-check-lg" aria-hidden="true"></i> Crear y elegir
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif
@endsection
