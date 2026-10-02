@extends('layouts.app')

@php($esEdicion = $proveedor->exists)

@section('title', $esEdicion ? 'Editar proveedor' : 'Nuevo proveedor')

@section('content')
    <a href="{{ route('proveedores.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">
            {{ $esEdicion ? "Editar «{$proveedor->razon_social}»" : 'Nuevo proveedor' }}
        </h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate
        action="{{ $esEdicion ? route('proveedores.update', $proveedor) : route('proveedores.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="row g-3">
            {{-- Identificación --}}
            <div class="col-12 col-md-7">
                <label for="razon_social" class="form-label">
                    Nombre o razón social <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control @error('razon_social') is-invalid @enderror" id="razon_social"
                    name="razon_social" value="{{ old('razon_social', $proveedor->razon_social) }}" required maxlength="150"
                    autofocus aria-describedby="ayudaRazon @error('razon_social') errorRazon @enderror">
                <div id="ayudaRazon" class="form-text">Como figura en sus facturas.</div>
                @error('razon_social')
                    <div id="errorRazon" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-5">
                <label for="cuit" class="form-label">
                    CUIT <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control @error('cuit') is-invalid @enderror" id="cuit" name="cuit"
                    value="{{ old('cuit', $esEdicion ? $proveedor->cuitFormateado() : '') }}" required inputmode="numeric"
                    placeholder="30-71234567-8" aria-describedby="ayudaCuit @error('cuit') errorCuit @enderror">
                <div id="ayudaCuit" class="form-text">
                    Once dígitos. Podés escribirlo con guiones: se guarda sin ellos.
                </div>
                @error('cuit')
                    <div id="errorCuit" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Contacto --}}
            <div class="col-12 col-md-4">
                <label for="contacto" class="form-label">Persona de contacto</label>
                <input type="text" class="form-control @error('contacto') is-invalid @enderror" id="contacto"
                    name="contacto" value="{{ old('contacto', $proveedor->contacto) }}" maxlength="100"
                    placeholder="Mesa de pedidos" aria-describedby="@error('contacto') errorContacto @enderror">
                @error('contacto')
                    <div id="errorContacto" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-4">
                <label for="email" class="form-label">Correo</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email"
                    name="email" value="{{ old('email', $proveedor->email) }}" maxlength="150"
                    aria-describedby="ayudaEmail @error('email') errorEmail @enderror">
                <div id="ayudaEmail" class="form-text">
                    Obligatorio si los pedidos se le mandan por correo.
                </div>
                @error('email')
                    <div id="errorEmail" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-4">
                <label for="telefono" class="form-label">Teléfono</label>
                <input type="text" class="form-control @error('telefono') is-invalid @enderror" id="telefono"
                    name="telefono" value="{{ old('telefono', $proveedor->telefono) }}" maxlength="30"
                    aria-describedby="@error('telefono') errorTelefono @enderror">
                @error('telefono')
                    <div id="errorTelefono" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Cómo se le pide --}}
            <div class="col-12">
                <hr class="my-2">
                <h2 class="h6 text-body-secondary">Cómo se le hacen los pedidos</h2>
                <p class="text-body-secondary small">
                    El sistema siempre genera el mismo PDF con el pedido. Esto define qué hacer con él
                    una vez descargado.
                </p>
            </div>

            {{-- Lista cerrada y corta: queda nativa, sin data-buscable --}}
            <div class="col-12 col-md-6">
                <label for="canal_pedido" class="form-label">
                    Canal de pedido <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select @error('canal_pedido') is-invalid @enderror" id="canal_pedido"
                    name="canal_pedido" required aria-describedby="ayudaCanal @error('canal_pedido') errorCanal @enderror">
                    @foreach (App\Models\Proveedor::CANALES as $valor => $texto)
                        <option value="{{ $valor }}" @selected((string) old('canal_pedido', $proveedor->canal_pedido) === (string) $valor)>
                            {{ $texto }}
                        </option>
                    @endforeach
                </select>
                <div id="ayudaCanal" class="form-text">
                    Por correo: se le adjunta el PDF. Por portal: se sube al sitio del proveedor.
                    Manual: se le pasa por teléfono o mensajería.
                </div>
                @error('canal_pedido')
                    <div id="errorCanal" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Sólo corresponde al canal del portal. El JS lo oculta y lo
                 deshabilita cuando no corresponde —un campo deshabilitado no
                 viaja—; sin JavaScript queda visible y el servidor lo rechaza
                 con un mensaje que lo explica. --}}
            <div class="col-12 col-md-6 @if (old('canal_pedido', $proveedor->canal_pedido) !== 'portal_externo') d-none @endif" data-grupo-portal>
                <label for="portal_url" class="form-label">
                    Dirección del portal <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="url" class="form-control @error('portal_url') is-invalid @enderror" id="portal_url"
                    name="portal_url" value="{{ old('portal_url', $proveedor->portal_url) }}" maxlength="255"
                    placeholder="https://pedidos.proveedor.com.ar"
                    aria-describedby="ayudaPortal @error('portal_url') errorPortal @enderror">
                <div id="ayudaPortal" class="form-text">Donde se cargan los pedidos.</div>
                @error('portal_url')
                    <div id="errorPortal" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="plazo_entrega_dias" class="form-label">
                    Plazo de entrega <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <div class="input-group">
                    <input type="number" class="form-control @error('plazo_entrega_dias') is-invalid @enderror"
                        id="plazo_entrega_dias" name="plazo_entrega_dias"
                        value="{{ old('plazo_entrega_dias', $proveedor->plazo_entrega_dias) }}" required min="0"
                        max="365" step="1"
                        aria-describedby="ayudaPlazo @error('plazo_entrega_dias') errorPlazo @enderror">
                    <span class="input-group-text">días</span>
                    @error('plazo_entrega_dias')
                        <div id="errorPlazo" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div id="ayudaPlazo" class="form-text">
                    Es informativo. Poné 0 si todavía no lo sabés.
                </div>
            </div>

            {{-- Estado y cascada --}}
            <div class="col-12">
                <hr class="my-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(old('activo', $proveedor->activo ?? true))>
                    <label class="form-check-label" for="activo">Proveedor activo</label>
                    <div class="form-text">
                        Un proveedor inactivo no se ofrece al cargar productos ni al generar órdenes de compra.
                        Su historial de compras se conserva igual.
                    </div>
                </div>

                {{-- La cascada la pide el usuario, con el número a la vista.
                     Mismo patrón que categorías. --}}
                @if ($esEdicion && $proveedor->activo && $productosActivos > 0)
                    <div class="al-desactivar alert alert-warning mt-3 mb-0">
                        <p class="mb-2">
                            Este proveedor provee <strong>{{ $productosActivos }}</strong>
                            {{ $productosActivos === 1 ? 'producto activo' : 'productos activos' }}.
                            Si lo desactivás, siguen como están: se pueden seguir vendiendo.
                        </p>

                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" value="1" id="desactivar_productos"
                                name="desactivar_productos" @checked(old('desactivar_productos'))>
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

        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear proveedor' }}
            </button>

            <a href="{{ route('proveedores.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
