@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id; el cliente, sus direcciones y su cuenta los trae
     pantallas/clientes.js por la API. --}}
@php($esEdicion = isset($clienteId))

@section('title', $esEdicion ? 'Editar cliente' : 'Nuevo cliente')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1" data-titulo-cliente>{{ $esEdicion ? 'Editar cliente' : 'Nuevo cliente' }}</h1>
        <p class="text-body-secondary small mb-0">
            Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
        </p>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. --}}
    <form method="POST" novalidate data-formulario-cliente data-cliente="{{ $clienteId ?? '' }}"
        data-volver="{{ route('clientes.index') }}"
        data-editar="{{ route('clientes.edit', ['cliente' => '__ID__']) }}"
        class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <h2 class="h5 mb-3">Datos para facturar</h2>

        {{-- Los valores por omisión del alta son los del modelo, no datos de un cliente. --}}
        @include('clientes._campos', ['cliente' => new App\Models\Cliente(['condicion_iva' => 'consumidor_final', 'tipo_doc' => 'dni'])])

        @if ($esEdicion)
            <div class="alert alert-secondary mt-4 mb-0" role="alert" data-cuenta-tienda hidden>
                <i class="bi bi-person-check" aria-hidden="true"></i>
                Este cliente entra a la tienda online con <span data-campo="cuenta-email"></span>. Esa cuenta no
                sirve para el sistema de gestión.
            </div>
        @endif

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('clientes.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear cliente' }}
            </button>
        </div>
    </form>

    @if ($esEdicion)
        {{-- El enlace de la cuenta nueva (D-26): viaja sólo en la respuesta del alta de la cuenta y no se vuelve
             a mostrar. Afuera del formulario de arriba: un formulario no puede ir dentro de otro. --}}
        <div class="alert alert-warning mt-4" role="alert" data-enlace-tienda hidden>
            <p class="fw-semibold mb-1">
                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                Enlace para <span data-campo="enlace-nombre"></span>
            </p>
            <p class="small mb-2">
                Con este enlace elige su contraseña, y después entra a la app con su correo. Pasáselo ahora:
                vence en una hora, sirve una sola vez y no se vuelve a mostrar.
            </p>
            <input type="text" class="form-control font-monospace" value="" readonly data-campo="enlace-url"
                aria-label="Enlace para elegir la contraseña" onfocus="this.select()">
        </div>

        {{-- La cuenta de la tienda para un cliente del mostrador (D-26). Sólo con el permiso; el JS la muestra
             si el cliente es de mostrador, y le dice a quien no tiene correo que lo cargue primero. --}}
        @can('cliente.dar_acceso_tienda')
            <div class="card card-body border-0 shadow-sm mt-4" data-acceso-tienda hidden>
                <h2 class="h5 mb-1">Acceso a la tienda online</h2>

                <p class="text-body-secondary small mb-0" data-sin-correo hidden>
                    Para que pueda comprar desde la app, cargale un correo en los datos de arriba y guardá:
                    es con lo que va a entrar.
                </p>

                <div data-con-correo hidden>
                    <p class="text-body-secondary small mb-3">
                        Va a entrar con <strong data-campo="acceso-correo"></strong> y la contraseña que elija con el
                        enlace. Sus compras del mostrador siguen en su ficha.
                    </p>

                    <form method="POST" novalidate data-formulario-acceso>
                        <p class="alert alert-danger" data-errores-formulario hidden></p>
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-sm-5">
                                <label for="acceso_nombre" class="form-label">Nombre <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" id="acceso_nombre" name="nombre" maxlength="100" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-5">
                                <label for="acceso_apellido" class="form-label">Apellido <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" id="acceso_apellido" name="apellido" maxlength="100" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-2 d-grid">
                                <button type="submit" class="btn btn-outline-dark">Darle acceso</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

        {{-- Direcciones. Sólo en la edición: no se le puede colgar una dirección a un cliente que todavía no
             existe. La lista la pinta el JS con la ficha. --}}
        <div class="card card-body border-0 shadow-sm mt-4" data-direcciones>
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Direcciones</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Se utilizan para los envíos. La predeterminada se ofrecerá en primer lugar."
                        aria-label="Información sobre direcciones de envío">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                <a href="{{ route('direcciones.create', $clienteId) }}" class="btn btn-outline-dark">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar dirección
                </a>
            </div>

            <div data-direcciones-lista>
                <p class="text-body-secondary small border-top pt-3 mb-0">Cargando…</p>
            </div>

            <template data-direccion-fila>
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap border-top py-3">
                    <div>
                        <div data-campo="resumen"></div>
                        <span class="badge text-bg-dark mt-1" data-campo="predeterminada" hidden>Predeterminada</span>
                    </div>

                    <div class="text-nowrap">
                        <a href="{{ route('direcciones.edit', ['cliente' => $clienteId, 'direccion' => '__DIR__']) }}"
                            class="btn btn-sm btn-outline-dark" data-accion="editar-direccion">
                            <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                        </a>

                        <button type="button" class="btn btn-sm btn-outline-danger" data-direccion-eliminar>
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    @endif
@endsection
