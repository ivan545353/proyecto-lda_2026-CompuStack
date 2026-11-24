@extends('layouts.app')

{{-- Shell (regla 37): en la edición sólo llega el id; la persona, su rol y si es la propia cuenta los trae
     pantallas/personal.js por la API. Las opciones de rol del alta, también. --}}
@php($esEdicion = isset($usuarioId))

@section('title', $esEdicion ? 'Editar integrante' : 'Nuevo integrante')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1" data-titulo-personal>{{ $esEdicion ? 'Editar integrante' : 'Nuevo integrante' }}</h1>
            <p class="text-body-secondary small mb-0">
                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
            </p>
        </div>

        <a href="{{ route('personal.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    {{-- Envía por la API: los errores de validación los marca el módulo central en cada campo. Los datos
         laborales se llaman como su clave en el error (`empleado.legajo`) para que el error caiga en su campo. --}}
    <form method="POST" novalidate data-formulario-personal data-usuario="{{ $usuarioId ?? '' }}"
        data-volver="{{ route('personal.index') }}" class="card card-body border-0 shadow-sm">
        <p class="alert alert-danger" data-errores-formulario hidden></p>

        <h2 class="h5 mb-3">Datos de la cuenta</h2>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control" id="nombre" name="nombre" maxlength="100" autofocus
                    placeholder="Por ejemplo: Sofía">
            </div>

            <div class="col-12 col-md-6">
                <label for="apellido" class="form-label">
                    Apellido <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control" id="apellido" name="apellido" maxlength="100"
                    placeholder="Por ejemplo: Gutiérrez">
            </div>

            <div class="col-12 col-md-6">
                <label for="email" class="form-label">
                    Correo <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                    placeholder="usuario@sistema.local" aria-describedby="ayudaEmail">
                <div id="ayudaEmail" class="form-text">Con este correo inicia sesión.</div>
            </div>

            {{-- El rol sólo se elige en el alta. En la edición se muestra como dato: cambiarlo es una acción
                 aparte, con su propio permiso. Es la corrección de C-3, donde el perfil viajaba en el body
                 del formulario de edición. --}}
            <div class="col-12 col-md-6">
                @if ($esEdicion)
                    <span class="form-label d-block">Rol</span>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge text-bg-dark" data-campo="rol"></span>
                            <span class="text-body-secondary small">(Personal de gestión)</span>
                        </div>

                        @can('usuario.cambiar_rol')
                            {{-- El JS lo muestra salvo en la propia cuenta: nadie se cambia el rol a sí mismo. --}}
                            <a href="{{ route('personal.rol.edit', $usuarioId) }}" data-cambiar-rol hidden
                                class="btn btn-sm btn-outline-dark text-nowrap">
                                <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cambiar rol
                            </a>
                        @endcan
                    </div>
                    <div class="form-text">
                        El rol determina qué puede hacer el usuario.
                    </div>
                @else
                    <label for="rol_id" class="form-label">
                        Rol <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    {{-- Las opciones son los roles del personal: las pide el JS. --}}
                    <select class="form-select" id="rol_id" name="rol_id" data-buscable data-buscable-diferido
                        aria-describedby="ayudaRol">
                        <option value="">Elegí un rol</option>
                    </select>
                    <div id="ayudaRol" class="form-text">
                        Define los permisos dentro del sistema.
                    </div>
                @endif
            </div>

            @unless ($esEdicion)
                <div class="col-12 col-md-6">
                    <label for="password" class="form-label">
                        Contraseña <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                            autocomplete="new-password" placeholder="Mínimo 8 caracteres" aria-describedby="ayudaPassword">
                        <button type="button" class="btn btn-outline-secondary" id="verContrasena"
                            title="Mostrar u ocultar contraseña" aria-label="Mostrar u ocultar contraseña">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="ayudaPassword" class="form-text">Mínimo 8 caracteres, con letras y números.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label for="password_confirmation" class="form-label">
                        Repetir contraseña <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                        placeholder="Reingresá la misma contraseña" autocomplete="new-password">
                </div>
            @endunless

            <div class="col-12">
                <div class="form-check">
                    {{-- Marcada en el alta; en la edición la pone el JS con lo que trae la ficha. --}}
                    <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                        @checked(! $esEdicion) aria-describedby="ayudaActivo">
                    <label class="form-check-label" for="activo">Puede iniciar sesión</label>
                    <div id="ayudaActivo" class="form-text">
                        Quitar el acceso no borra nada: el usuario conserva su historial.
                    </div>
                    {{-- En la propia cuenta el JS deshabilita la casilla y muestra esto; el servidor lo rechaza igual. --}}
                    <div class="form-text text-warning-emphasis" data-acceso-propio hidden>
                        <i class="bi bi-info-circle me-1"></i> No podés quitarte el acceso a vos mismo.
                    </div>
                </div>
            </div>
        </div>

        <fieldset class="mt-4">
            <legend class="h5">Datos laborales</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="empleado_legajo" class="form-label">
                        Legajo <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" class="form-control" id="empleado_legajo" name="empleado.legajo" maxlength="20"
                        placeholder="EMP-0031">
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_dni" class="form-label">
                        DNI <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" inputmode="numeric" class="form-control" id="empleado_dni" name="empleado.dni"
                        placeholder="38123456" aria-describedby="ayudaDni">
                    <div id="ayudaDni" class="form-text">Sin puntos ni espacios.</div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_telefono" class="form-label">Teléfono</label>
                    <input type="text" class="form-control" id="empleado_telefono" name="empleado.telefono" maxlength="30"
                        placeholder="Por ejemplo: 297-4551122">
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_fecha_ingreso" class="form-label">
                        Fecha de ingreso <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="date" class="form-control" id="empleado_fecha_ingreso" name="empleado.fecha_ingreso"
                        max="{{ now()->toDateString() }}">
                </div>

                @if ($esEdicion)
                    <div class="col-12 col-md-4">
                        <label for="empleado_fecha_baja" class="form-label">Fecha de baja</label>
                        <input type="date" class="form-control" id="empleado_fecha_baja" name="empleado.fecha_baja"
                            max="{{ now()->toDateString() }}" aria-describedby="ayudaBaja">
                        <div id="ayudaBaja" class="form-text">
                            Dejalo vacío si sigue en actividad.
                        </div>
                    </div>
                @endif
            </div>
        </fieldset>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('personal.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear usuario' }}
            </button>
        </div>
    </form>

    @if ($esEdicion)
        @can('usuario.resetear_password')
            <div class="card card-body border-0 shadow-sm mt-4" data-acceso-cuenta>
                <h2 class="h5 mb-1">Acceso a la cuenta</h2>

                {{-- En la propia ficha el enlace no tiene sentido: la persona conoce su contraseña y tiene una
                     pantalla para cambiarla. Se muestra el camino correcto en vez de esconder el bloque. --}}
                <div data-cuenta-propia hidden>
                    <p class="text-body-secondary small mb-3">
                        Esta es tu cuenta. Para cambiar tu propia contraseña no hace falta un enlace.
                    </p>

                    <a href="{{ route('cuenta.password.edit') }}" class="btn btn-outline-dark">
                        <i class="bi bi-key" aria-hidden="true"></i> Cambiar mi contraseña
                    </a>
                </div>

                <div data-cuenta-ajena hidden>
                    <p class="text-body-secondary small">
                        Si <span data-campo="nombre-pila"></span> no se acuerda de su contraseña, generá un enlace y
                        pasáselo.
                    </p>

                    {{-- El enlace viaja sólo en la respuesta y se muestra una vez: si se perdió, se genera otro. --}}
                    <div class="alert alert-warning" role="alert" data-enlace-restablecimiento hidden>
                        <p class="fw-semibold mb-1">
                            <i class="bi bi-link-45deg" aria-hidden="true"></i>
                            Enlace para <span data-campo="enlace-nombre"></span>
                        </p>

                        <p class="small mb-2">
                            Copialo y pasáselo ahora. Vence en una hora, sirve una sola vez, y no se
                            vuelve a mostrar.
                        </p>

                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" id="enlaceRestablecimiento" value=""
                                readonly data-campo="enlace-url" aria-label="Enlace de restablecimiento" onfocus="this.select()">

                            <button class="btn btn-outline-dark" type="button" data-accion="copiar-enlace">
                                <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                            </button>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-dark" data-accion="generar-enlace">
                        <i class="bi bi-key" aria-hidden="true"></i> Generar enlace de restablecimiento
                    </button>
                </div>
            </div>
        @endcan
    @endif
@endsection
