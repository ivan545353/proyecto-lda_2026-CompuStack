@extends('layouts.app')

@use('App\Support\Importe')

@php
    $esAlta = !$venta->exists;

    // Las filas que se muestran, en este orden de preferencia: lo que el usuario
    // mandó si hubo un error de validación, las líneas de la venta si se está
    // editando, y nada si es un alta (el JS pone una fila vacía).
    //
    // Las claves no se renumeran nunca: PHP recibe lineas[0] y lineas[7] como dos
    // elementos, y un contador que sólo crece evita toda la clase de errores que
    // produce reindexar al quitar una fila del medio.
    $lineasCargadas = old(
        'lineas',
        $esAlta
            ? []
            : $venta->lineas
                ->map(fn($linea) => ['producto_id' => $linea->producto_id, 'cantidad' => $linea->cantidad])
                ->all(),
    );

    $proximoIndice = $lineasCargadas === [] ? 0 : max(array_keys($lineasCargadas)) + 1;

    $descuentoInicial = old('descuento_porcentaje', $esAlta ? null : ($venta->descuentoPorcentaje() ?: null));
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
                Se emite como <strong>presupuesto</strong>: no descuenta stock ni genera comprobante.
                Se convierte en venta cuando se cobra.
            @else
                Las líneas que ya estaban <strong>conservan el precio de cuando se cotizaron</strong>.
                Un producto que agregues ahora se cotiza al precio de hoy.
            @endif
        </p>
    </div>

    @include('partials.errores')

    @if ($productos->isEmpty())
        <div class="card card-body border-0 shadow-sm">
            <p class="mb-0">
                No hay ningún producto activo en el catálogo, así que no hay nada que vender.
                @can('producto.ver')
                    Cargá al menos uno desde el <a href="{{ route('productos.index') }}">catálogo</a>.
                @endcan
            </p>
        </div>
    @else
        {{-- Precio y disponible de cada producto, para la vista previa. Va como JSON
             y no en atributos data- de cada <option>: ahí tendría que repetirse en
             cada fila que se agregue. --}}
        <script type="application/json" id="preciosDelCatalogo">@json($catalogo)</script>

        {{-- Los precios congelados de esta venta, por producto. Es lo que permite que
             la pantalla muestre lo mismo que va a guardar el servidor: un producto
             que ya estaba conserva su precio, y si se lo cambia y se lo vuelve a
             elegir, lo recupera. En el alta este mapa está vacío. --}}
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
                            <label for="cliente_id" class="form-label">Cliente</label>
                            {{-- Sin `required`: la opción vacía es una opción válida
                                 —consumidor final— y no un texto de ayuda. El
                                 inicializador de los selectores con búsqueda usa
                                 justamente `required` para decidir eso. --}}
                            <select class="form-select @error('cliente_id') is-invalid @enderror" id="cliente_id"
                                name="cliente_id" data-buscable aria-describedby="ayudaCliente">
                                <option value="">Consumidor final (sin identificar)</option>
                                @foreach ($clientes as $cliente)
                                    <option value="{{ $cliente->id }}" @selected((string) old('cliente_id', $venta->cliente_id) === (string) $cliente->id)>
                                        {{ $cliente->razon_social }}@if ($cliente->nro_doc)
                                            — {{ App\Models\Cliente::TIPOS_DOC[$cliente->tipo_doc] ?? '' }}
                                            {{ $cliente->nro_doc }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>

                            <div id="ayudaCliente" class="form-text">
                                La venta de mostrador no necesita identificar al comprador.
                            </div>

                            @error('cliente_id')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="col-12 col-sm-6 col-lg-3">
                            <label for="descuento_porcentaje" class="form-label">Descuento</label>
                            {{-- Texto y no type="number": un campo numérico del
                                 navegador reinterpreta "7,5" según el idioma del
                                 sistema. --}}
                            <div class="input-group">
                                <input type="text" inputmode="decimal" autocomplete="off"
                                    class="form-control text-end @error('descuento_porcentaje') is-invalid @enderror"
                                    id="descuento_porcentaje" name="descuento_porcentaje"
                                    value="{{ $descuentoInicial === null ? '' : Importe::paraFormulario($descuentoInicial) }}"
                                    placeholder="0" aria-describedby="ayudaDescuento">
                                <span class="input-group-text" aria-hidden="true">%</span>
                            </div>

                            <div id="ayudaDescuento" class="form-text">
                                Hasta {{ Importe::paraFormulario($tope) }} %.
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
                        <div>
                            <h2 class="h5 mb-1">Productos</h2>
                            <p class="text-body-secondary small mb-0">
                                El precio lo pone el sistema: se lee del catálogo y queda congelado en
                                la venta.
                            </p>
                        </div>

                        <button type="button"
                            class="btn btn-sm btn-outline-dark align-self-stretch align-self-sm-auto d-inline-flex align-items-center justify-content-center gap-1 text-nowrap"
                            data-agregar-linea>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar producto
                        </button>
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
                            @foreach ($lineasCargadas as $indice => $linea)
                                @include('ventas._linea', [
                                    'indice' => $indice,
                                    'linea' => $linea,
                                    'productos' => $productos,
                                    'catalogo' => $catalogo,
                                    'congelados' => $congelados,
                                ])
                            @endforeach

                            {{-- La plantilla va DENTRO del tbody: un <tr> dentro de
                                    un <template> suelto no sobrevive al parseo,
                                    porque una fila fuera de una tabla se descarta. Su
                                    contenido vive en un fragmento aparte, así que no
                                    aparece en los querySelectorAll('tr'). --}}
                            <template id="plantillaLineaVenta">
                                @include('ventas._linea', [
                                    'indice' => '__INDICE__',
                                    'linea' => null,
                                    'productos' => $productos,
                                    'catalogo' => $catalogo,
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

                    <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas hidden>
                        La venta no tiene productos. Agregá al menos uno.
                    </p>

                </div>
            </div>

            <div class="d-grid d-sm-flex gap-2">
                <button type="submit" class="btn btn-acento d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    {{ $esAlta ? 'Emitir el presupuesto' : 'Guardar los cambios' }}
                </button>

                <a href="{{ $esAlta ? route('ventas.index') : route('ventas.show', $venta) }}"
                    class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center">Cancelar</a>
            </div>
        </form>
    @endif
@endsection
