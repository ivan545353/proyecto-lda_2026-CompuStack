@extends('layouts.app')

@use('App\Support\Importe')
@use('App\Http\Requests\BusquedaRequest')

@php
    $esAlta = !$venta->exists;

    $descuentoInicial = old('descuento_porcentaje', $esAlta ? null : ($venta->descuentoPorcentaje() ?: null));
    $idsCargados = collect($lineas)->pluck('producto_id')->filter(fn($id) => ctype_digit((string) $id))->implode(',');
@endphp

@section('title', $esAlta ? 'Nueva venta' : "Editar {$venta->numeroFormateado()}")

@section('content')
    <a href="{{ $esAlta ? route('ventas.index') : route('ventas.show', $venta) }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>
        {{ $esAlta ? 'Volver al listado' : 'Volver a la venta' }}
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esAlta ? 'Nueva venta' : "Editar {$venta->numeroFormateado()}" }}</h1>
        <p class="text-body-secondary small mb-0">
            @if ($esAlta)
                Se emite como <strong>presupuesto</strong>: no descuenta stock ni genera comprobante hasta que se cobre.
            @else
                Las líneas cargadas <strong>conservan su precio original</strong>. Cualquier producto que agregues ahora se
                cotiza al valor actual.
            @endif
        </p>
    </div>

    @include('partials.errores')

    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Sin JavaScript no se pueden agregar productos ni elegir un cliente en esta pantalla.</strong>
                Los dos buscadores le preguntan al servidor mientras escribís: mandar el
                catálogo completo en la página la hacía pesar cinco megabytes, y con el doble
                de productos dejaba de abrir. Lo que sí funciona acá: ver los renglones que
                la venta ya tiene, cambiarles la cantidad, cargar el descuento y guardar.
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
        <script type="application/json" id="preciosCongelados">@json($congelados)</script>

        <form method="POST" novalidate action="{{ $esAlta ? route('ventas.store') : route('ventas.update', $venta) }}">
            @csrf
            @unless ($esAlta)
                @method('PUT')
            @endunless

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
                            <select class="form-select @error('cliente_id') is-invalid @enderror" id="cliente_id"
                                name="cliente_id" aria-describedby="ayudaCliente" data-buscable
                                data-buscable-url="{{ route('busqueda.clientes') }}"
                                data-buscable-minimo="{{ BusquedaRequest::MINIMO }}">
                                <option value="">Sin identificar</option>
                                @if ($cliente)
                                    <option value="{{ $cliente->id }}" selected
                                        data-data="{{ json_encode(['text' => $cliente->razonConDocumento()], JSON_UNESCAPED_UNICODE) }}">
                                        {{ $cliente->razonConDocumento() }}</option>
                                @endif
                            </select>

                            <div id="ayudaCliente" class="form-text">
                                Opcional en ventas de mostrador. Buscalo por nombre o por documento.
                            </div>
                            @can('cliente.crear')
                                <div id="anuncioCliente" class="form-text text-success-emphasis" aria-live="polite"></div>
                            @endcan
                            @error('cliente_id')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label for="descuento_porcentaje" class="form-label">Descuento</label>
                            <div class="input-group">
                                <input type="text" inputmode="decimal" autocomplete="off"
                                    class="form-control text-end @error('descuento_porcentaje') is-invalid @enderror"
                                    id="descuento_porcentaje" name="descuento_porcentaje"
                                    value="{{ $descuentoInicial === null ? '' : Importe::paraFormulario($descuentoInicial) }}"
                                    placeholder="0" aria-describedby="ayudaDescuento">
                                <span class="input-group-text" aria-hidden="true">%</span>
                            </div>

                            <div id="ayudaDescuento" class="form-text">
                                Tope permitido: {{ Importe::paraFormulario($tope) }}%.
                            </div>

                            @error('descuento_porcentaje')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12 col-lg-3">
                            <label for="observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control @error('observaciones') is-invalid @enderror" id="observaciones" name="observaciones"
                                rows="2" maxlength="255" placeholder="Pasa a buscarlo el jueves">{{ old('observaciones', $venta->observaciones) }}</textarea>

                            @error('observaciones')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4" data-venta data-proximo-indice="{{ $proximoIndice }}">
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
                                data-buscable-url="{{ route('busqueda.productos') }}"
                                data-buscable-minimo="{{ BusquedaRequest::MINIMO }}"
                                data-buscable-detalle="precio,disponible" data-buscable-excluir="{{ $idsCargados }}"
                                aria-describedby="ayudaBuscadorProducto">
                                <option value="">Buscá por código o nombre</option>
                            </select>

                            <div id="ayudaBuscadorProducto" class="form-text">
                                Ej: TEC-00123 o «teclado». Un código completo se agrega con Enter.
                            </div>
                        </div>
                    </div>

                    @error('lineas')
                        <div class="alert alert-danger">{{ $message }}</div>
                    @enderror

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
                            @foreach ($lineas as $indice => $linea)
                                @include('ventas._linea', [
                                    'indice' => $indice,
                                    'linea' => $linea,
                                    'productos' => $productos,
                                    'congelados' => $congelados,
                                ])
                            @endforeach

                            <template id="plantillaLineaVenta">
                                @include('ventas._linea', [
                                    'indice' => '__INDICE__',
                                    'linea' => null,
                                    'productos' => $productos,
                                    'congelados' => $congelados,
                                ])
                            </template>
                        </tbody>

                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-body-secondary">Subtotal</td>
                                <td class="text-end text-nowrap" data-subtotal-venta>
                                    {{ Importe::pesos($esAlta ? 0 : $venta->subtotal) }}
                                </td>
                                <td></td>
                            </tr>

                            <tr data-fila-descuento @unless (!$esAlta && (float) $venta->descuento > 0) hidden @endunless>
                                <td colspan="3" class="text-end text-body-secondary">Descuento</td>
                                <td class="text-end text-nowrap" data-descuento-venta>
                                    − {{ Importe::pesos($esAlta ? 0 : $venta->descuento) }}
                                </td>
                                <td></td>
                            </tr>

                            <tr>
                                <td colspan="3" class="text-end fw-semibold">Total</td>
                                <td class="text-end fw-semibold text-nowrap" data-total-venta>
                                    {{ Importe::pesos($esAlta ? 0 : $venta->total) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>

                    <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas
                        @unless ($lineas === []) hidden @endunless>
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

        {{-- Afuera del formulario de la venta: un <form> no puede contener otro. Postea a
             la misma ruta que clientes/crear, así que valida con el mismo ClienteRequest. --}}
        @can('cliente.crear')
            <div class="modal fade" id="nuevoCliente" tabindex="-1" aria-labelledby="nuevoClienteTitulo"
                aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <form method="POST" novalidate action="{{ route('clientes.store') }}" class="modal-content"
                        data-alta-rapida="#cliente_id" data-alta-rapida-anuncio="#anuncioCliente">
                        @csrf

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

                            <div class="alert alert-danger" role="alert" data-alta-rapida-error hidden></div>

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
