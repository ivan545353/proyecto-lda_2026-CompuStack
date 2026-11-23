@extends('layouts.app')


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

    @include('compras._pestanas-pedido', ['activa' => 'armar'])

    {{-- Shell (tramo G, regla 37): los renglones con los que se entra, si hay algo que pedir y los
         proveedores de cada producto llegan por la API. Guardar es `POST /api/v1/compras`. --}}
    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Sin JavaScript esta pantalla no funciona.</strong>
                Los productos, sus proveedores y el pedido van y vienen por la API: mandar el catálogo
                completo en la página la hacía pesar seis megabytes y usar 392 MB de memoria.
            </div>
        </div>
    </noscript>

    <div data-pantalla="armador">
    <p class="text-body-secondary" data-cargando>Cargando el armador…</p>

        <div class="card card-body border-0 shadow-sm" data-sin-pedibles hidden>
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

        <form novalidate data-formulario-armador data-volver="{{ route('compras.index', ['estado' => 'borrador']) }}" hidden>
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="card border-0 shadow-sm mb-4" data-armador data-proximo-indice="0"
                data-proveedores-url="/productos/__ID__/proveedores">
                <div class="card-body">
                    <div
                        class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h5 mb-0">Productos a pedir</h2>
                            <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                                data-bs-toggle="tooltip" data-bs-placement="top"
                                data-bs-title="El costo se sugiere con el último registrado para ese proveedor. Si cambió, podés escribir el nuevo."
                                aria-label="Ayuda sobre costos sugeridos">
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                            </button>
                        </div>

                        <button type="button"
                            class="btn btn-sm btn-outline-dark align-self-stretch align-self-sm-auto d-inline-flex align-items-center justify-content-center gap-1 text-nowrap"
                            data-agregar-linea>
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar producto
                        </button>
                    </div>

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

                            {{-- La plantilla va DENTRO de un tbody: un <tr> dentro
                                    de un <template> suelto no sobrevive al parseo,
                                    porque una fila fuera de una tabla se descarta. Su
                                    contenido vive en un fragmento aparte, así que no
                                    aparece en los querySelectorAll('tr'). --}}
                            <template id="plantillaLineaPedido">
                                @include('compras._linea-pedido', ['indice' => '__INDICE__'])
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

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2">
                <a href="{{ route('compras.index') }}"
                    class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center">Cancelar</a>
                <button type="submit"
                    class="btn btn-acento px-4 d-inline-flex align-items-center justify-content-center gap-1">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> Crear los pedidos en borrador
                </button>
            </div>
        </form>
    </div>
@endsection
