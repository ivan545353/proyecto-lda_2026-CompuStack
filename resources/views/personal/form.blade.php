@extends('layouts.app')

@php
    $esEdicion = $usuario->exists;
    $esPropiaCuenta = $esEdicion && $usuario->is(auth()->user());
@endphp

@section('title', $esEdicion ? 'Editar integrante' : 'Nuevo integrante')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                {{ $esEdicion ? "Editar a {$usuario->nombre_completo}" : 'Nuevo integrante' }}
            </h1>
            <p class="text-body-secondary small mb-0">
                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
            </p>
        </div>

        <a href="{{ route('personal.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ $esEdicion ? route('personal.update', $usuario) : route('personal.store') }}"
        class="card card-body border-0 shadow-sm">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <h2 class="h5 mb-3">Datos de la cuenta</h2>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label for="nombre" class="form-label">
                    Nombre <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control @error('nombre') is-invalid @enderror" id="nombre"
                    name="nombre" value="{{ old('nombre', $usuario->nombre) }}" maxlength="100" autofocus
                    placeholder="Por ejemplo: Sofía" aria-describedby="@error('nombre') errorNombre @enderror">
                @error('nombre')
                    <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="apellido" class="form-label">
                    Apellido <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="text" class="form-control @error('apellido') is-invalid @enderror" id="apellido"
                    name="apellido" value="{{ old('apellido', $usuario->apellido) }}" maxlength="100"
                    placeholder="Por ejemplo: Gutiérrez" aria-describedby="@error('apellido') errorApellido @enderror">
                @error('apellido')
                    <div id="errorApellido" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="col-12 col-md-6">
                <label for="email" class="form-label">
                    Correo <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email"
                    name="email" value="{{ old('email', $usuario->email) }}" maxlength="150"
                    placeholder="usuario@sistema.local" aria-describedby="ayudaEmail @error('email') errorEmail @enderror">
                <div id="ayudaEmail" class="form-text">Con este correo inicia sesión.</div>
                @error('email')
                    <div id="errorEmail" class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- El rol sólo se elige en el alta. En la edición se muestra como
                 dato: cambiarlo es una acción aparte, con su propio permiso.
                 Es la corrección de C-3, donde el perfil viajaba en el body del
                 formulario de edición. --}}
            <div class="col-12 col-md-6">
                @if ($esEdicion)
                    <span class="form-label d-block">Rol</span>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge {{ $usuario->rol->esDeGestion() ? 'text-bg-dark' : 'text-bg-info' }}">
                                {{ $usuario->rol->nombre }}
                            </span>
                            <span
                                class="text-body-secondary small">({{ $usuario->rol->esDeGestion() ? 'Personal de gestión' : 'Cuenta de tienda' }})</span>
                        </div>

                        @can('usuario.cambiar_rol')
                            @unless ($usuario->is(auth()->user()))
                                <a href="{{ route('personal.rol.edit', $usuario) }}"
                                    class="btn btn-sm btn-outline-dark text-nowrap">
                                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cambiar rol
                                </a>
                            @endunless
                        @endcan
                    </div>
                    <div class="form-text">
                        El rol determina qué puede hacer el usuario.
                    </div>
                @else
                    <label for="rol_id" class="form-label">
                        Rol <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('rol_id') is-invalid @enderror" id="rol_id" name="rol_id"
                        data-buscable aria-describedby="ayudaRol @error('rol_id') errorRol @enderror">
                        <option value="">Elegí un rol</option>
                        @foreach ($roles as $rol)
                            <option value="{{ $rol->id }}" @selected((int) old('rol_id') === $rol->id)>
                                {{ $rol->nombre }}
                            </option>
                        @endforeach
                    </select>
                    <div id="ayudaRol" class="form-text">
                        Determina qué puede hacer la persona dentro del sistema.
                    </div>
                    @error('rol_id')
                        <div id="errorRol" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                @endif
            </div>

            @unless ($esEdicion)
                <div class="col-12 col-md-6">
                    <label for="password" class="form-label">
                        Contraseña <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="input-group">
                        <input type="password" class="form-control @error('password') is-invalid @enderror" id="password"
                            name="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres"
                            aria-describedby="ayudaPassword @error('password') errorPassword @enderror">
                        <button type="button" class="btn btn-outline-secondary" id="verContrasena"
                            title="Mostrar u ocultar contraseña" aria-label="Mostrar u ocultar contraseña">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div id="ayudaPassword" class="form-text">Mínimo 8 caracteres, con letras y números.</div>
                    @error('password')
                        <div id="errorPassword" class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
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
                @if ($esPropiaCuenta)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                            checked disabled>
                        <input type="hidden" name="activo" value="1">
                        <label class="form-check-label" for="activo">Puede iniciar sesión</label>
                        <div class="form-text text-warning-emphasis">
                            <i class="bi bi-info-circle me-1"></i> No podés quitarte el acceso a vos mismo.
                        </div>
                    </div>
                @else
                    <div class="form-check">
                        <input class="form-check-input @error('activo') is-invalid @enderror" type="checkbox"
                            value="1" id="activo" name="activo" @checked(old('activo', $usuario->activo ?? true))
                            aria-describedby="ayudaActivo @error('activo') errorActivo @enderror">
                        <label class="form-check-label" for="activo">Puede iniciar sesión</label>
                        <div id="ayudaActivo" class="form-text">
                            Quitar el acceso no borra nada: el usuario conserva su historial.
                        </div>
                        @error('activo')
                            <div id="errorActivo" class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>
        </div>

        <fieldset class="mt-4">
            <legend class="h5">Datos laborales</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="empleado_legajo" class="form-label">
                        Legajo <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" class="form-control @error('empleado.legajo') is-invalid @enderror"
                        id="empleado_legajo" name="empleado[legajo]" maxlength="20" placeholder="EMP-0031"
                        value="{{ old('empleado.legajo', $usuario->empleado?->legajo) }}"
                        aria-describedby="@error('empleado.legajo') errorLegajo @enderror">
                    @error('empleado.legajo')
                        <div id="errorLegajo" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_dni" class="form-label">
                        DNI <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" inputmode="numeric"
                        class="form-control @error('empleado.dni') is-invalid @enderror" id="empleado_dni"
                        name="empleado[dni]" value="{{ old('empleado.dni', $usuario->empleado?->dni) }}"
                        placeholder="Sin puntos: 38123456"
                        aria-describedby="ayudaDni @error('empleado.dni') errorDni @enderror">
                    <div id="ayudaDni" class="form-text">Sin puntos.</div>
                    @error('empleado.dni')
                        <div id="errorDni" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_telefono" class="form-label">Teléfono</label>
                    <input type="text" class="form-control @error('empleado.telefono') is-invalid @enderror"
                        id="empleado_telefono" name="empleado[telefono]" maxlength="30"
                        placeholder="Por ejemplo: 297-4551122"
                        value="{{ old('empleado.telefono', $usuario->empleado?->telefono) }}">
                    @error('empleado.telefono')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-4">
                    <label for="empleado_fecha_ingreso" class="form-label">
                        Fecha de ingreso <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="date" class="form-control @error('empleado.fecha_ingreso') is-invalid @enderror"
                        id="empleado_fecha_ingreso" name="empleado[fecha_ingreso]" max="{{ now()->toDateString() }}"
                        value="{{ old('empleado.fecha_ingreso', $usuario->empleado?->fecha_ingreso?->toDateString()) }}"
                        aria-describedby="@error('empleado.fecha_ingreso') errorIngreso @enderror">
                    @error('empleado.fecha_ingreso')
                        <div id="errorIngreso" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @if ($esEdicion)
                    <div class="col-12 col-md-4">
                        <label for="empleado_fecha_baja" class="form-label">Fecha de baja</label>
                        <input type="date" class="form-control @error('empleado.fecha_baja') is-invalid @enderror"
                            id="empleado_fecha_baja" name="empleado[fecha_baja]" max="{{ now()->toDateString() }}"
                            value="{{ old('empleado.fecha_baja', $usuario->empleado?->fecha_baja?->toDateString()) }}"
                            aria-describedby="ayudaBaja @error('empleado.fecha_baja') errorBaja @enderror">
                        <div id="ayudaBaja" class="form-text">
                            Vacía si sigue trabajando.
                        </div>
                        @error('empleado.fecha_baja')
                            <div id="errorBaja" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>
        </fieldset>


        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear Usuario' }}
            </button>

            <a href="{{ route('personal.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    @if ($esEdicion)
        @can('usuario.resetear_password')
            <div class="card card-body border-0 shadow-sm mt-4">
                <h2 class="h5 mb-1">Acceso a la cuenta</h2>

                @if ($usuario->is(auth()->user()))
                    {{-- En la propia ficha el enlace no tiene sentido: la persona
                         conoce su contraseña y tiene una pantalla para cambiarla.
                         Se muestra el camino correcto en vez de esconder el
                         bloque, que dejaría la pregunta sin respuesta. --}}
                    <p class="text-body-secondary small mb-3">
                        Esta es tu cuenta. Para cambiar tu propia contraseña no hace falta un enlace.
                    </p>

                    <a href="{{ route('cuenta.password.edit') }}" class="btn btn-outline-dark align-self-start">
                        <i class="bi bi-key" aria-hidden="true"></i> Cambiar mi contraseña
                    </a>
                @else
                    <p class="text-body-secondary small">
                        Si {{ $usuario->nombre }} no se acuerda de su contraseña, generá un enlace y pasáselo.
                    </p>

                    {{-- El enlace se muestra una sola vez, en el flash de la sesión.
                         No se guarda: si se perdió, se genera otro. --}}
                    @if (session('enlace_restablecimiento'))
                        <div class="alert alert-warning" role="alert">
                            <p class="fw-semibold mb-1">
                                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                Enlace para {{ session('nombre_restablecido') }}
                            </p>

                            <p class="small mb-2">
                                Copialo y pasáselo ahora. Vence en una hora, sirve una sola vez, y no se
                                vuelve a mostrar.
                            </p>

                            <div class="input-group">
                                <input type="text" class="form-control font-monospace" id="enlaceRestablecimiento"
                                    value="{{ session('enlace_restablecimiento') }}" readonly
                                    aria-label="Enlace de restablecimiento">

                                <button class="btn btn-outline-dark" type="button" id="copiarEnlace">
                                    <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                                </button>
                            </div>
                        </div>

                        <script>
                            // Mejora progresiva: sin JavaScript el enlace se selecciona
                            // y se copia a mano, que es lo que el campo readonly permite.
                            document.getElementById('copiarEnlace')?.addEventListener('click', async function() {
                                const campo = document.getElementById('enlaceRestablecimiento');
                                campo.select();

                                try {
                                    await navigator.clipboard.writeText(campo.value);
                                    this.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
                                } catch {
                                    document.execCommand('copy');
                                }
                            });
                        </script>
                    @endif

                    <form method="POST" action="{{ route('personal.restablecer', $usuario) }}"
                        onsubmit="return confirm(@js("Se va a generar un enlace para que {$usuario->nombre} configure su contraseña. La actual va a seguir sirviendo hasta que la cambie. ¿Continuar?"));">
                        @csrf
                        <button type="submit" class="btn btn-outline-dark">
                            <i class="bi bi-key" aria-hidden="true"></i> Generar enlace de restablecimiento
                        </button>
                    </form>
                @endif
            </div>
        @endcan
    @endif
@endsection
