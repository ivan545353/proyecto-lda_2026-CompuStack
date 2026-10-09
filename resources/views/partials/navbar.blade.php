<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container-fluid gap-3">

        {{-- Identidad visual / Acceso directo al panel --}}
        <a class="navbar-brand" href="{{ route('panel') }}">
            <img src="{{ asset('assets/imagotipo.svg') }}" alt="{{ config('app.name') }}" height="40">
        </a>

        {{-- Botón de alternancia para dispositivos móviles --}}
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
            aria-controls="navbarMain" aria-expanded="false" aria-label="Abrir menú">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            {{-- Módulos del sistema habilitados según permisos del usuario --}}
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-3">
                <li class="nav-item">
                    <a class="nav-link text-black {{ request()->routeIs('panel') ? 'active' : '' }}"
                        href="{{ route('panel') }}"
                        @if (request()->routeIs('panel')) aria-current="page" @endif>Inicio</a>
                </li>

                @can('venta.ver')
                    <li class="nav-item">
                        <a class="nav-link text-black {{ request()->routeIs('ventas.*') ? 'active' : '' }}"
                            href="{{ route('ventas.index') }}"
                            @if (request()->routeIs('ventas.*')) aria-current="page" @endif>Ventas</a>
                    </li>
                @endcan

                @can('rol.ver')
                    <li class="nav-item">
                        <a class="nav-link text-black {{ request()->routeIs('roles.*') ? 'active' : '' }}"
                            href="{{ route('roles.index') }}"
                            @if (request()->routeIs('roles.*')) aria-current="page" @endif>Roles</a>
                    </li>
                @endcan

                @canany(['usuario.ver', 'cliente.ver'])
                    @php($enUsuarios = request()->routeIs('personal.*', 'clientes.*', 'direcciones.*'))
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-black {{ $enUsuarios ? 'active' : '' }}" href="#"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Usuarios
                        </a>
                        <ul class="dropdown-menu">
                            @can('usuario.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('personal.*') ? 'active' : '' }}"
                                        href="{{ route('personal.index') }}"
                                        @if (request()->routeIs('personal.*')) aria-current="page" @endif>Personal</a>
                                </li>
                            @endcan
                            @can('cliente.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('clientes.*', 'direcciones.*') ? 'active' : '' }}"
                                        href="{{ route('clientes.index') }}"
                                        @if (request()->routeIs('clientes.*', 'direcciones.*')) aria-current="page" @endif>Clientes</a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcanany

                @canany(['producto.ver', 'categoria.ver', 'marca.ver'])
                    @php($enCatalogo = request()->routeIs('productos.*', 'categorias.*', 'marcas.*'))
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-black {{ $enCatalogo ? 'active' : '' }}" href="#"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Catálogo
                        </a>
                        <ul class="dropdown-menu">
                            @can('producto.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('productos.*') ? 'active' : '' }}"
                                        href="{{ route('productos.index') }}"
                                        @if (request()->routeIs('productos.*')) aria-current="page" @endif>Productos</a>
                                </li>
                            @endcan
                            @can('categoria.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('categorias.*') ? 'active' : '' }}"
                                        href="{{ route('categorias.index') }}"
                                        @if (request()->routeIs('categorias.*')) aria-current="page" @endif>Categorías</a>
                                </li>
                            @endcan
                            @can('marca.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('marcas.*') ? 'active' : '' }}"
                                        href="{{ route('marcas.index') }}"
                                        @if (request()->routeIs('marcas.*')) aria-current="page" @endif>Marcas</a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcanany

                @canany(['proveedor.ver', 'compra.ver', 'stock.ver'])
                    @php($enCompras = request()->routeIs('proveedores.*', 'compras.*', 'stock.*'))
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-black {{ $enCompras ? 'active' : '' }}" href="#"
                            role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Compras
                        </a>
                        <ul class="dropdown-menu">
                            @can('proveedor.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('proveedores.*') ? 'active' : '' }}"
                                        href="{{ route('proveedores.index') }}"
                                        @if (request()->routeIs('proveedores.*')) aria-current="page" @endif>Proveedores</a>
                                </li>
                            @endcan

                            @can('compra.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('compras.*') ? 'active' : '' }}"
                                        href="{{ route('compras.index') }}"
                                        @if (request()->routeIs('compras.*')) aria-current="page" @endif>Órdenes de compra</a>
                                </li>
                            @endcan

                            @can('stock.ver')
                                <li>
                                    <a class="dropdown-item {{ request()->routeIs('stock.*') ? 'active' : '' }}"
                                        href="{{ route('stock.index') }}"
                                        @if (request()->routeIs('stock.*')) aria-current="page" @endif>Movimientos de stock</a>
                                </li>
                            @endcan
                        </ul>
                    </li>
                @endcanany

                {{-- Cada módulo nuevo se suma acá dentro de su correspondiente @can --}}
            </ul>

            {{-- Menú de usuario y control de sesión --}}
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-black" href="#" role="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> {{ auth()->user()->nombre }}
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        {{-- Rol asignado --}}
                        <li>
                            <span class="dropdown-item-text small text-body-secondary">
                                {{ auth()->user()->rol->nombre }}
                            </span>
                        </li>

                        <li>
                            <hr class="dropdown-divider">
                        </li>

                        {{-- Cuenta propia. No lleva @can: no requiere permiso. --}}
                        <li>
                            <a class="dropdown-item {{ request()->routeIs('cuenta.password.*') ? 'active' : '' }}"
                                href="{{ route('cuenta.password.edit') }}">
                                <i class="bi bi-key" aria-hidden="true"></i> Cambiar mi contraseña
                            </a>
                        </li>

                        <li>
                            <hr class="dropdown-divider">
                        </li>

                        {{-- Cierre de sesión (vía POST con protección CSRF) --}}
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item">
                                    <i class="bi bi-box-arrow-left"></i> Cerrar sesión
                                </button>
                            </form>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

    </div>
</nav>
