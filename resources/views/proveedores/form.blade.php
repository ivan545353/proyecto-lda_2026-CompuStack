@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id; el proveedor lo trae pantallas/proveedores.js. --}}
@php($esEdicion = isset($proveedorId))

@section('title', $esEdicion ? 'Editar proveedor' : 'Nuevo proveedor')

@section('content')
    <a href="{{ route('proveedores.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-proveedor>{{ $esEdicion ? 'Editar proveedor' : 'Nuevo proveedor' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" novalidate data-formulario-proveedor data-proveedor="{{ $proveedorId ?? '' }}"
        data-volver="{{ route('proveedores.index') }}" class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <div class="row g-3">
            {{-- Identificación --}}
            <div class="col-12 col-md-7">
                <label for="razon_social" class="form-label">
                    Nombre o razón social <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control" id="razon_social" name="razon_social" required maxlength="150"
                    autofocus aria-describedby="ayudaRazon">
                <div id="ayudaRazon" class="form-text">Como figura en sus facturas.</div>
            </div>

            <div class="col-12 col-md-5">
                <label for="cuit" class="form-label">
                    CUIT <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control" id="cuit" name="cuit" required inputmode="numeric"
                    placeholder="30-71234567-8" aria-describedby="ayudaCuit">
                <div id="ayudaCuit" class="form-text">
                    Once dígitos. Podés escribirlo con guiones: se guarda sin ellos.
                </div>
            </div>

            {{-- Contacto --}}
            <div class="col-12 col-md-4">
                <label for="contacto" class="form-label">Persona de contacto</label>
                <input type="text" class="form-control" id="contacto" name="contacto" maxlength="100"
                    placeholder="Mesa de pedidos">
            </div>

            <div class="col-12 col-md-4">
                <label for="email" class="form-label">Correo</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                    aria-describedby="ayudaEmail">
                <div id="ayudaEmail" class="form-text">
                    Obligatorio si los pedidos se le mandan por correo.
                </div>
            </div>

            <div class="col-12 col-md-4">
                <label for="telefono" class="form-label">Teléfono</label>
                <input type="text" class="form-control" id="telefono" name="telefono" maxlength="30">
            </div>

            {{-- Cómo se le pide --}}
            <div class="col-12">
                <hr class="my-2">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <h2 class="h6 text-body-secondary mb-0">Modalidad de pedidos</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="El sistema genera la orden en PDF. Este ajuste indica la vía de envío al proveedor."
                        aria-label="Información sobre modalidad de pedidos">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            {{-- Lista cerrada y corta: queda nativa, sin data-buscable --}}
            <div class="col-12 col-md-6">
                <label for="canal_pedido" class="form-label">
                    Canal de pedido <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select" id="canal_pedido" name="canal_pedido" required
                    aria-describedby="ayudaCanal">
                    @foreach (App\Models\Proveedor::CANALES as $valor => $texto)
                        <option value="{{ $valor }}" @selected(! $esEdicion && $valor === 'manual')>{{ $texto }}</option>
                    @endforeach
                </select>
                <div id="ayudaCanal" class="form-text">
                    Por correo (adjunta PDF), por portal web del proveedor o manual (teléfono/mensajería).
                </div>
            </div>

            {{-- Sólo corresponde al canal del portal. app.js lo oculta y lo deshabilita cuando no
                 corresponde —un campo deshabilitado no viaja—; el servidor rechaza lo que no
                 corresponde con un mensaje que lo explica. --}}
            <div class="col-12 col-md-6 d-none" data-grupo-portal>
                <label for="portal_url" class="form-label">
                    Dirección del portal <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="url" class="form-control" id="portal_url" name="portal_url" maxlength="255"
                    placeholder="https://pedidos.proveedor.com.ar" aria-describedby="ayudaPortal">
                <div id="ayudaPortal" class="form-text">Donde se cargan los pedidos.</div>
            </div>

            <div class="col-12 col-md-6">
                <label for="plazo_entrega_dias" class="form-label">
                    Plazo de entrega <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <div class="input-group has-validation">
                    <input type="number" class="form-control" id="plazo_entrega_dias" name="plazo_entrega_dias"
                        value="0" required min="0" max="365" step="1" aria-describedby="ayudaPlazo">
                    <span class="input-group-text">días</span>
                </div>
                <div id="ayudaPlazo" class="form-text">
                    Estimado en días corridos (0 si se desconoce).
                </div>
            </div>

            {{-- Estado y cascada --}}
            <div class="col-12">
                <hr class="my-2">
                <div class="form-check">
                    <input class="form-check-input interruptor-activo" type="checkbox" value="1" id="activo"
                        name="activo" @checked(! $esEdicion)>
                    <label class="form-check-label" for="activo">Proveedor activo</label>
                    <div class="form-text">
                        Un proveedor inactivo no se ofrece al cargar productos ni al generar órdenes de compra.
                        Su historial de compras se conserva igual.
                    </div>
                </div>

                {{-- La cascada la pide quien edita, con el número a la vista. Sólo en la edición, y sólo si el
                     proveedor tiene productos activos: lo decide el JS con la ficha. Mismo patrón que categorías. --}}
                @if ($esEdicion)
                    <div class="al-desactivar alert alert-warning mt-3 mb-0" data-aviso-productos hidden>
                        <p class="mb-2" data-productos-texto></p>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" value="1" id="desactivar_productos"
                                name="desactivar_productos">
                            <label class="form-check-label" for="desactivar_productos">
                                Desactivar también sus productos
                            </label>
                            <div class="form-text">
                                Para cuando se deja de trabajar con la marca entera. No se puede deshacer en un
                                solo paso: reactivar el proveedor después no vuelve a activar lo que se desactivó acá.
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('proveedores.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear proveedor' }}
            </button>
        </div>
    </form>
@endsection
