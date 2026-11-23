@extends('layouts.app')

@section('title', 'Editar orden de compra')

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">
            Editar la orden <span data-numero-orden></span>
        </h1>
        <p class="text-body-secondary small mb-0">
            Se guarda en borrador. Mientras esté en borrador se puede modificar; una vez aprobada,
            el monto queda comprometido y las líneas se congelan.
        </p>
    </div>

    {{-- Shell (tramo G, regla 37): la orden llega por `GET /api/v1/compras/{orden}`, los proveedores por
         `GET /api/v1/compras/proveedores` y guardar es `PATCH /api/v1/compras/{orden}`. Del servidor sólo
         vienen el id de la orden y el de su proveedor, para la primera URL del buscador. --}}
    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Sin JavaScript esta pantalla no funciona.</strong>
                La orden, sus productos y los cambios van y vienen por la API.
            </div>
        </div>
    </noscript>

    <div data-pantalla="editar-orden" data-orden="{{ $orden->id }}"
        data-volver="{{ route('compras.show', $orden) }}" aria-busy="true">
        <p class="text-body-secondary" data-cargando>Cargando la orden…</p>

        <form novalidate data-formulario-orden hidden>
            <p class="alert alert-danger" data-errores-formulario hidden></p>

            <div class="card card-body border-0 shadow-sm mb-4">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="proveedor_id" class="form-label">
                            Proveedor <span class="text-danger" aria-hidden="true">*</span>
                        </label>
                        <select class="form-select" id="proveedor_id" name="proveedor_id" required
                            data-proveedor-orden data-buscable data-buscable-diferido
                            aria-describedby="ayudaProveedorOrden">
                            <option value="">Elegí un proveedor</option>
                        </select>
                        <div id="ayudaProveedorOrden" class="form-text">
                            Sólo se ofrecen los proveedores activos.
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="observaciones" class="form-label">Observaciones</label>
                        <textarea class="form-control" id="observaciones" name="observaciones" rows="2" maxlength="255"
                            aria-describedby="ayudaObs"></textarea>
                        <div id="ayudaObs" class="form-text">Van impresas en el pedido que recibe el proveedor.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <h2 class="h5 mb-0">Productos del pedido</h2>
                        <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                            data-bs-toggle="tooltip" data-bs-placement="top"
                            data-bs-title="Un producto por línea. El costo sugiere el último registrado; podés modificarlo si varió."
                            aria-label="Información sobre productos y costos del pedido">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                        </button>
                    </div>

                    {{-- El buscador ES el gesto de agregar: se escribe, se elige, y el
                         renglón aparece con el producto puesto, la cantidad en 1 y el
                         último costo de ese proveedor si el servidor lo mandó. --}}
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-7">
                            <label for="buscadorProducto" class="form-label">Agregar un producto</label>
                            <select id="buscadorProducto" class="form-select" data-buscador-producto data-buscable
                                data-buscable-url="/productos?{{ http_build_query(['forma' => 'gestion', 'estado' => 'activos', 'proveedor_id' => $orden->proveedor_id]) }}"
                                data-buscable-minimo="2" data-buscable-detalle="disponible"
                                data-buscable-excluir="" aria-describedby="ayudaBuscadorProducto">
                                <option value="">Buscá por código o nombre</option>
                            </select>

                            <div id="ayudaBuscadorProducto" class="form-text">
                                Sólo los productos que provee el proveedor elegido. Ej: TEC-00123 o «teclado».
                            </div>

                            <div class="form-text text-warning-emphasis" data-falta-proveedor hidden>
                                Elegí primero el proveedor: no se le puede pedir a alguien algo que no vende.
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0" data-lineas-orden data-proximo-indice="0"
                            data-productos-url="/productos?forma=gestion&amp;estado=activos&amp;proveedor_id=__PROVEEDOR__"
                            style="min-width: 40rem;">
                            <caption class="visually-hidden">Productos de la orden de compra</caption>
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="min-width: 14rem;">Producto</th>
                                    <th scope="col" class="text-end" style="width: 7rem; min-width: 5.5rem;">Cantidad</th>
                                    <th scope="col" class="text-end" style="width: 9.5rem; min-width: 8.5rem;">Costo unitario
                                    </th>
                                    <th scope="col" class="text-end" style="width: 7.5rem; min-width: 6rem;">Subtotal</th>
                                    <th scope="col" style="width: 3.5rem; min-width: 3rem;">
                                        <span class="visually-hidden">Quitar</span>
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {{-- La plantilla va DENTRO del tbody: un <tr> dentro de un
                                     <template> suelto no sobrevive al parseo del navegador,
                                     porque una fila fuera de una tabla se descarta. --}}
                                <template id="plantillaLinea">
                                    @include('compras._linea', ['indice' => '__INDICE__'])
                                </template>
                            </tbody>

                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end fw-semibold">Total estimado</td>
                                    <td class="text-end fw-semibold" data-total-orden>$ 0,00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas hidden>
                        Todavía no hay ningún producto. Buscalo arriba por código o por nombre.
                    </p>
                </div>
            </div>

            <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
                <a href="{{ route('compras.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-acento px-4">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                    Guardar cambios
                </button>
            </div>
        </form>
    </div>
@endsection
