@extends('layouts.app')

@php
    $esEdicion = $usuario->exists;
    $esPropiaCuenta = $esEdicion && $usuario->is(auth()->user());

    // En la edición el ámbito lo fija el rol que ya tiene: desde acá el rol no
    // se cambia (C-3). En el alta sale de lo elegido, o de lo que quedó tras
    // un error de validación.
    $rolActual = $esEdicion ? $usuario->rol : $roles->firstWhere('id', (int) old('rol_id'));
    $ambito = $rolActual?->ambito;
@endphp

@section('title', $esEdicion ? 'Editar persona' : 'Nueva persona')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                {{ $esEdicion ? "Editar a {$usuario->nombre_completo}" : 'Nueva persona' }}
            </h1>
            <p class="text-body-secondary small mb-0">
                Los campos con <span class="text-danger" aria-hidden="true">*</span> son obligatorios.
            </p>
        </div>

        <a href="{{ route('usuarios.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ $esEdicion ? route('usuarios.update', $usuario) : route('usuarios.store') }}"
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
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge {{ $usuario->rol->esDeGestion() ? 'text-bg-dark' : 'text-bg-info' }}">
                            {{ $usuario->rol->nombre }}
                        </span>
                        <span
                            class="text-body-secondary small">({{ $usuario->rol->esDeGestion() ? 'Personal de gestión' : 'Cuenta de tienda' }})</span>
                    </div>
                    <div class="form-text">
                        El rol determina qué puede hacer la persona
                    </div>
                @else
                    <label for="rol_id" class="form-label">
                        Rol <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('rol_id') is-invalid @enderror" id="rol_id" name="rol_id"
                        data-buscable aria-describedby="ayudaRol @error('rol_id') errorRol @enderror">
                        <option value="">Elegí un rol</option>
                        @foreach ($roles as $rol)
                            <option value="{{ $rol->id }}" data-ambito="{{ $rol->ambito }}"
                                @selected((int) old('rol_id') === $rol->id)>
                                {{ $rol->nombre }} ({{ $rol->ambito === 'gestion' ? 'Personal' : 'Tienda' }})
                            </option>
                        @endforeach
                    </select>
                    <div id="ayudaRol" class="form-text">
                        Según el rol se piden los datos laborales o los datos de facturación.
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
                            Quitar el acceso no borra nada: la persona conserva su historial.
                        </div>
                        @error('activo')
                            <div id="errorActivo" class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                @endif
            </div>
        </div>

        {{-- Datos laborales: sólo para el personal (roles de ámbito gestión) --}}
        <fieldset class="mt-4" id="bloqueEmpleado" @if ($ambito !== 'gestion') hidden @endif>
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

        {{-- Datos de facturación: sólo para cuentas de la tienda --}}
        <fieldset class="mt-4" id="bloqueCliente" @if ($ambito !== 'tienda') hidden @endif>
            <legend class="h5">Datos de facturación</legend>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="cliente_razon_social" class="form-label">
                        Nombre o razón social <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" class="form-control @error('cliente.razon_social') is-invalid @enderror"
                        id="cliente_razon_social" name="cliente[razon_social]" maxlength="150"
                        placeholder="Nombre o empresa para comprobantes"
                        value="{{ old('cliente.razon_social', $usuario->cliente?->razon_social) }}"
                        aria-describedby="@error('cliente.razon_social') errorRazon @enderror">
                    @error('cliente.razon_social')
                        <div id="errorRazon" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="cliente_condicion_iva" class="form-label">
                        Condición frente al IVA <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('cliente.condicion_iva') is-invalid @enderror"
                        id="cliente_condicion_iva" name="cliente[condicion_iva]"
                        aria-describedby="ayudaIva @error('cliente.condicion_iva') errorIva @enderror">
                        @foreach (['consumidor_final' => 'Consumidor final', 'responsable_inscripto' => 'Responsable inscripto', 'monotributo' => 'Monotributo', 'exento' => 'Exento'] as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('cliente.condicion_iva', $usuario->cliente?->condicion_iva ?? 'consumidor_final') === $valor)>
                                {{ $texto }}</option>
                        @endforeach
                    </select>
                    <div id="ayudaIva" class="form-text">Define si le corresponde Factura A o B.</div>
                    @error('cliente.condicion_iva')
                        <div id="errorIva" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-3">
                    <label for="cliente_tipo_doc" class="form-label">
                        Tipo de documento <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('cliente.tipo_doc') is-invalid @enderror" id="cliente_tipo_doc"
                        name="cliente[tipo_doc]">
                        @foreach (['dni' => 'DNI', 'cuit' => 'CUIT', 'cuil' => 'CUIL'] as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('cliente.tipo_doc', $usuario->cliente?->tipo_doc ?? 'dni') === $valor)>{{ $texto }}
                            </option>
                        @endforeach
                    </select>
                    @error('cliente.tipo_doc')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-3">
                    <label for="cliente_nro_doc" class="form-label">Número de documento</label>
                    <input type="text" inputmode="numeric"
                        class="form-control @error('cliente.nro_doc') is-invalid @enderror" id="cliente_nro_doc"
                        name="cliente[nro_doc]" value="{{ old('cliente.nro_doc', $usuario->cliente?->nro_doc) }}"
                        placeholder="Sin guiones ni puntos"
                        aria-describedby="ayudaDoc @error('cliente.nro_doc') errorDoc @enderror">
                    <div id="ayudaDoc" class="form-text">Obligatorio si factura A o es monotributista.</div>
                    @error('cliente.nro_doc')
                        <div id="errorDoc" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-3">
                    <label for="cliente_email" class="form-label">Correo de contacto</label>
                    <input type="email" class="form-control @error('cliente.email') is-invalid @enderror"
                        id="cliente_email" name="cliente[email]" maxlength="150" placeholder="facturacion@empresa.com"
                        value="{{ old('cliente.email', $usuario->cliente?->email) }}">
                    @error('cliente.email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-3">
                    <label for="cliente_telefono" class="form-label">Teléfono</label>
                    <input type="text" class="form-control @error('cliente.telefono') is-invalid @enderror"
                        id="cliente_telefono" name="cliente[telefono]" maxlength="30"
                        placeholder="Por ejemplo: 297-4551122"
                        value="{{ old('cliente.telefono', $usuario->cliente?->telefono) }}">
                    @error('cliente.telefono')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </fieldset>

        <div class="d-grid d-sm-flex gap-2 mt-4">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear persona' }}
            </button>

            <a href="{{ route('usuarios.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>

    @unless ($esEdicion)
        <script>
            // Mejora progresiva: muestra el bloque de datos que corresponde al
            // ámbito del rol elegido. Sin JavaScript se ven los dos y el
            // formulario sigue funcionando, porque quién valida qué lo decide
            // UsuarioRequest en el servidor leyendo roles.ambito.
            document.addEventListener('DOMContentLoaded', function() {
                const rol = document.getElementById('rol_id');
                const bloques = {
                    gestion: document.getElementById('bloqueEmpleado'),
                    tienda: document.getElementById('bloqueCliente'),
                };

                if (!rol) return;

                function actualizarSatelites() {
                    const opt = rol.selectedOptions ? rol.selectedOptions[0] : null;
                    const ambito = opt?.dataset.ambito ?? null;

                    for (const [clave, bloque] of Object.entries(bloques)) {
                        if (bloque) {
                            bloque.hidden = clave !== ambito;
                        }
                    }
                }

                rol.addEventListener('change', actualizarSatelites);
                actualizarSatelites();
            });
        </script>
    @endunless
@endsection
