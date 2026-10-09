@extends('layouts.app')

@php
    // Lo que el usuario mandó si hubo un error de validación; si no, una sola línea
    // vacía la pone el JS. Las claves no se renumeran: PHP recibe lineas[0] y
    // lineas[7] como dos elementos.
    $lineasCargadas = old('lineas', []);
    $proximoIndice = $lineasCargadas === [] ? 0 : max(array_keys($lineasCargadas)) + 1;
@endphp

@section('title', 'Armar pedido')

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">Armar pedido</h1>
        <p class="text-body-secondary small mb-0">
            Cargá los productos que necesitás y elegí a quién le pedís cada uno. Al guardar se
            crea <strong>un pedido en borrador por proveedor</strong>, y cada uno se aprueba por
            separado.
        </p>
    </div>

    @include('partials.errores')

    @if ($productos->isEmpty())
        <div class="card card-body border-0 shadow-sm">
            <p class="mb-0">
                No hay ningún producto que se le pueda comprar a alguien. Para poder armar un
                pedido, cargale al menos un proveedor activo a algún producto desde el
                @can('producto.ver')
                    <a href="{{ route('productos.index') }}">catálogo</a>.
                @else
                    catálogo.
                @endcan
            </p>
        </div>
    @else
        {{-- El mapa producto → proveedores, con el costo de cada vínculo. Lo usa el
             JS para filtrar el selector de proveedor de cada línea y para sugerir el
             costo. Va como JSON y no en atributos data- de cada <option>: ahí
             tendría que repetirse en cada fila que se agregue. --}}
        <script type="application/json" id="proveedoresPorProducto">@json($mapa)</script>

        <form method="POST" novalidate action="{{ route('compras.pedido.store') }}">
            @csrf

            <div class="card border-0 shadow-sm mb-4" data-armador data-proximo-indice="{{ $proximoIndice }}">
                <div class="card-body">
                    <div
                        class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">Productos a pedir</h2>
                            <p class="text-body-secondary small mb-0">
                                El costo se sugiere con el último que ese proveedor cobró; escribilo si
                                cambió. Lo que escribas no se sobrescribe.
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

                    <table class="table align-middle mb-0" style="min-width: 48rem;">
                        <caption class="visually-hidden">
                            Productos a pedir, agrupados por el proveedor al que se le va a pedir cada uno
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 14rem;">Producto</th>
                                <th scope="col" style="width: 15rem; min-width: 12rem;">Proveedor</th>
                                <th scope="col" class="text-end" style="width: 7rem; min-width: 5.5rem;">Cantidad</th>
                                <th scope="col" class="text-end" style="width: 9.5rem; min-width: 8.5rem;">Costo unitario
                                </th>
                                <th scope="col" class="text-end" style="width: 7.5rem; min-width: 6rem;">Subtotal</th>
                                <th scope="col" style="width: 3.5rem; min-width: 3rem;"><span
                                        class="visually-hidden">Quitar</span></th>
                            </tr>
                        </thead>

                        {{-- El grupo de las líneas a las que todavía no se les
                                eligió proveedor. Existe siempre: es donde aterrizan
                                las filas nuevas. --}}
                        <tbody data-grupo="">
                            <tr class="table-warning" data-cabecera>
                                <th colspan="6" class="fw-normal small">
                                    Sin proveedor elegido — estas líneas no se van a guardar hasta que elijas a quién
                                    pedirles
                                </th>
                            </tr>

                            @foreach ($lineasCargadas as $indice => $linea)
                                @include('compras._linea-pedido', [
                                    'indice' => $indice,
                                    'linea' => $linea,
                                    'productos' => $productos,
                                    'proveedores' => $proveedores,
                                ])
                            @endforeach

                            {{-- La plantilla va DENTRO de un tbody: un <tr> dentro
                                    de un <template> suelto no sobrevive al parseo,
                                    porque una fila fuera de una tabla se descarta. Su
                                    contenido vive en un fragmento aparte, así que no
                                    aparece en los querySelectorAll('tr'). --}}
                            <template id="plantillaLineaPedido">
                                @include('compras._linea-pedido', [
                                    'indice' => '__INDICE__',
                                    'linea' => null,
                                    'productos' => $productos,
                                    'proveedores' => $proveedores,
                                ])
                            </template>
                        </tbody>

                        {{-- Un tbody por proveedor, clonado de acá por el JS y
                                ubicado en orden alfabético: el mismo orden en que el
                                servidor numera las órdenes. --}}
                        <template id="plantillaGrupoPedido">
                            <tbody data-grupo="">
                                <tr class="table-light" data-cabecera>
                                    <th colspan="6">
                                        <span data-nombre></span>
                                        <span class="fw-normal text-body-secondary small ms-2" data-resumen-grupo></span>
                                    </th>
                                </tr>
                            </tbody>
                        </template>
                    </table>

                    <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas hidden>
                        El pedido no tiene productos. Agregá al menos uno.
                    </p>

                    <div
                        class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center flex-wrap gap-2 border-top mt-3 pt-3">
                        <p class="small mb-0 text-body-secondary" data-resumen-pedido aria-live="polite"></p>

                        <div
                            class="d-flex justify-content-between justify-content-sm-end align-items-center w-100 w-sm-auto">
                            <span class="text-body-secondary small">Total estimado</span>
                            <span class="fw-semibold ms-2 fs-6" data-total-pedido>$ 0,00</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid d-sm-flex gap-2">
                <button type="submit" class="btn btn-acento d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Crear los pedidos en borrador
                </button>

                <a href="{{ route('compras.index') }}"
                    class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center">Cancelar</a>
            </div>
        </form>
    @endif
@endsection
