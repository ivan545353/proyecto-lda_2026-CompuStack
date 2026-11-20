@extends('layouts.app')

@use('App\Models\Producto')

{{-- Shell (regla 37): en la edición sólo llega el id. El producto, sus opciones, el desglose del
     precio y el historial los trae pantallas/productos.js de la API; cada parte, por su permiso. --}}
@php($esEdicion = isset($productoId))

@section('title', $esEdicion ? 'Editar producto' : 'Nuevo producto')

@section('content')
    <a href="{{ route('productos.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-producto>{{ $esEdicion ? 'Editar producto' : 'Nuevo producto' }}</h1>
        <p class="text-body-secondary small mb-0">Los campos con * son obligatorios.</p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" enctype="multipart/form-data" novalidate data-formulario-producto
        data-producto="{{ $productoId ?? '' }}" data-volver="{{ route('productos.index') }}">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        {{-- Identificación --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Identificación</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="codigo" class="form-label">Código <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control text-uppercase" id="codigo" name="codigo" required
                        maxlength="30" autocomplete="off" autofocus aria-describedby="ayudaCodigo">
                    <div id="ayudaCodigo" class="form-text">Letras, números, puntos y guiones, sin espacios.</div>
                </div>

                <div class="col-12 col-md-8">
                    <label for="nombre" class="form-label">Nombre <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="nombre" name="nombre" required maxlength="150">
                </div>

                <div class="col-12">
                    <label for="descripcion" class="form-label">Descripción</label>
                    <textarea class="form-control" id="descripcion" name="descripcion" rows="3" maxlength="5000"></textarea>
                </div>
            </div>
        </fieldset>

        {{-- Clasificación --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Clasificación</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="categoria_id" class="form-label">Categoría <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="categoria_id" name="categoria_id" required data-buscable="jerarquia"
                        data-buscable-diferido>
                        <option value="">Elegí una categoría</option>
                    </select>
                </div>

                <div class="col-12 col-sm-6 col-md-4">
                    <label for="marca_id" class="form-label">Marca</label>
                    <select class="form-select" id="marca_id" name="marca_id" data-buscable data-buscable-diferido>
                        <option value="">Sin marca</option>
                    </select>
                </div>

                {{-- El proveedor dejó de ser un campo del producto: se le puede comprar a varios, con un
                     costo distinto cada uno. Queda el camino a la pantalla que lo administra. --}}
                <div class="col-12">
                    <div class="form-text mb-0">
                        @if ($esEdicion)
                            Administrá costos y proveedores asociados desde <a
                                href="{{ route('producto-proveedores.index', $productoId) }}">sus proveedores</a>.
                        @else
                            Podés asociarle proveedores luego de crearlo.
                        @endif
                    </div>
                </div>
            </div>
        </fieldset>

        {{-- Precios --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3 d-flex align-items-center gap-2">
                <span>Precios</span>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="El precio sale del costo neto por la ganancia y los factores, más los impuestos. El de lista es el de contado con el recargo de cuotas adentro, y no se carga a mano."
                    aria-label="Información sobre los precios">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </legend>

            {{-- Advertencia operativa: si no se lee, el alta falla. --}}
            @unless ($esEdicion)
                <div class="alert alert-light border d-flex align-items-start gap-2 small py-2" role="note">
                    <i class="bi bi-exclamation-triangle text-warning flex-shrink-0 mt-1" aria-hidden="true"></i>
                    <span>
                        Un producto nuevo todavía no tiene costo, así que su precio se carga a mano.
                        Cuando reciba mercadería vas a poder pasarlo al cálculo automático.
                    </span>
                </div>
            @endunless

            <div class="row g-3">
                {{-- Gobierna a los demás campos, así que va primero. En el alta va marcada y fija: el
                     servidor la exige, y la pantalla la manda siempre. --}}
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="precio_manual" name="precio_manual"
                            @checked(!$esEdicion) @disabled(!$esEdicion) aria-describedby="ayudaManual">
                        <label class="form-check-label" for="precio_manual">Fijar el precio a mano</label>
                        <div id="ayudaManual" class="form-text">
                            Sin la marca, el precio lo calcula el motor y el ajuste masivo lo mueve.
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="precio_contado" class="form-label">
                        Precio de contado <span aria-hidden="true">*</span>
                    </label>

                    {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
                         "1.500,50" según el idioma del sistema. --}}
                    <div class="input-group has-validation">
                        <span class="input-group-text" aria-hidden="true">$</span>
                        <input type="text" inputmode="decimal" autocomplete="off" class="form-control" id="precio_contado"
                            name="precio_contado" aria-describedby="ayudaContado">
                    </div>

                    <div id="ayudaContado" class="form-text">Efectivo, transferencia o QR.</div>
                </div>

                <div class="col-12 col-md-4">
                    <span class="form-label d-block">Precio de lista</span>

                    <p class="form-control-plaintext fw-semibold mb-0" data-precio-lista>—</p>

                    <div class="form-text">
                        Tarjeta y cuotas. Sale del recargo que fija
                        @can('precio.ver')
                            <a href="{{ route('precios.index') }}">Precios</a>.
                        @else
                            la pantalla de Precios.
                        @endcan
                    </div>
                </div>

                <div class="col-6 col-md-2">
                    <label for="ganancia" class="form-label">Ganancia</label>
                    <input type="text" inputmode="decimal" autocomplete="off" class="form-control" id="ganancia"
                        name="ganancia" aria-describedby="ayudaGananciaProd">
                    <div id="ayudaGananciaProd" class="form-text">Vacía: hereda de la categoría.</div>
                </div>

                <div class="col-6 col-md-2">
                    <label for="alicuota_iva" class="form-label">IVA <span aria-hidden="true">*</span></label>
                    {{-- Las opciones salen del catálogo de impuestos, que es con lo que valida el Request. --}}
                    <select class="form-select" id="alicuota_iva" name="alicuota_iva" required></select>
                </div>
            </div>
        </fieldset>

        {{-- Stock y reposición --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <legend class="h5 mb-0">Stock y reposición</legend>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="{{ $esEdicion ? 'El stock y costo se actualizan mediante compras o ajustes.' : 'El producto se crea sin stock inicial; ingresa mediante compras o ajustes.' }}"
                    aria-label="Información sobre stock y costo">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            @if ($esEdicion)
                {{-- De sólo lectura: el stock y el costo se mueven con compras y ajustes (A-13). --}}
                <dl class="row mb-3">
                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Stock disponible</dt>
                    <dd class="col-6 col-md-3 fw-semibold"><span data-dato="disponible">—</span> u.</dd>

                    @can('precio.ver_costo')
                        <dt class="col-6 col-md-3 fw-normal text-body-secondary">Costo promedio</dt>
                        <dd class="col-6 col-md-3 fw-semibold">
                            <span data-dato="costo">—</span>
                            <span class="text-body-secondary fw-normal small">neto, sin IVA</span>
                        </dd>
                    @endcan
                </dl>

                <p class="form-text mt-n2 mb-3">
                    El stock y el costo se actualizan al recibir compras o al registrar ajustes de stock.
                </p>
            @endif

            <div class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="stock_minimo" class="form-label">Stock mínimo</label>
                    <input type="number" inputmode="numeric" min="0" max="100000" class="form-control" id="stock_minimo"
                        name="stock_minimo" value="0" aria-describedby="ayudaMinimo">
                    <div id="ayudaMinimo" class="form-text">Con esta cantidad o menos, hay que reponer.</div>
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="cantidad_reposicion" class="form-label">Cantidad a reponer</label>
                    <input type="number" inputmode="numeric" min="0" max="100000" class="form-control"
                        id="cantidad_reposicion" name="cantidad_reposicion" value="0" aria-describedby="ayudaReposicion">
                    <div id="ayudaReposicion" class="form-text">Cuántas unidades pedir cada vez.</div>
                </div>
            </div>
        </fieldset>

        {{-- Imágenes: en la edición, las actuales llegan por la API. --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Imágenes</legend>

            <x-gestor-imagenes :max="Producto::MAX_IMAGENES" descripcion="el producto" en-tienda :diferido="$esEdicion" />
        </fieldset>

        {{-- Estado --}}
        <div class="card card-body border-0 shadow-sm mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo" @checked(!$esEdicion)>
                <label class="form-check-label" for="activo">Producto activo</label>
                <div class="form-text">Un producto inactivo no se ofrece en ventas.</div>
            </div>

            @can('producto.editar')
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" value="1" id="destacado" name="destacado">
                    <label class="form-check-label" for="destacado">Destacado en la tienda</label>
                    <div class="form-text">Aparece en la sección «Destacados» del inicio de la tienda. Si el producto está inactivo, no se muestra.</div>
                </div>
            @endcan
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('productos.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear producto' }}
            </button>
        </div>
    </form>

    {{-- Cómo sale su precio: la estructura, con un @can por parte; los números, de la ficha. --}}
    @if ($esEdicion)
        <section class="card border-0 shadow-sm mb-4" data-seccion-precio>
            <div class="card-header bg-white border-0 pt-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Cómo sale su precio</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="El precio que tendría hoy según el costo, la ganancia heredada y los factores vigentes. Recalcular lo escribe en el producto y deja su fila en el historial."
                        aria-label="Información sobre el cálculo del precio">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                @can('precio.recalcular')
                    <button type="button" class="btn btn-sm btn-outline-dark text-nowrap" data-recalcular
                        aria-label="Recalcular el precio">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recalcular
                    </button>
                @endcan
            </div>

            <div class="card-body pt-2">
                <div class="alert alert-light border d-flex align-items-start gap-2 small py-2" role="note" data-aviso-manual hidden>
                    <i class="bi bi-pin-angle text-body-secondary flex-shrink-0 mt-1" aria-hidden="true"></i>
                    <span>
                        Este producto tiene el precio fijado a mano: ni «Recalcular» ni el ajuste
                        masivo lo cambian. Lo de abajo es lo que saldría si lo dejaras calcular.
                    </span>
                </div>

                @can('precio.ver_costo')
                    <p class="text-body-secondary mb-0" data-sin-costo hidden>
                        Todavía no tiene costo cargado, así que no hay precio que calcular. El costo
                        entra al recibir una compra.
                    </p>

                    <div class="table-responsive" data-calculo hidden>
                        <table class="table table-sm align-middle mb-0">
                            <caption class="visually-hidden">Del costo neto al precio de contado</caption>
                            <tbody data-calculo-filas></tbody>
                        </table>
                    </div>
                @endcan
            </div>

            {{-- El historial no muestra costos, así que no pide `precio.ver_costo`. --}}
            <div data-historial hidden>
                <div class="card-header bg-white border-0 border-top pt-3">
                    <h3 class="h6 mb-0">Últimos cambios de precio</h3>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">Historial de precios del producto</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Cuándo</th>
                                <th scope="col" class="text-end">De</th>
                                <th scope="col" class="text-end">A</th>
                                <th scope="col" class="d-none d-md-table-cell">Motivo</th>
                                <th scope="col" class="d-none d-md-table-cell">Quién</th>
                            </tr>
                        </thead>
                        <tbody data-historial-filas></tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
@endsection
