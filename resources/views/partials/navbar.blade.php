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

                @can('rol.ver')
                    <li class="nav-item">
                        <a class="nav-link text-black {{ request()->routeIs('roles.*') ? 'active' : '' }}"
                            href="{{ route('roles.index') }}"
                            @if (request()->routeIs('roles.*')) aria-current="page" @endif>Roles</a>
                    </li>
                @endcan

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

                        {{-- Perfil del usuario --}}
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="bi bi-person"></i> Mis datos
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
