<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container-fluid gap-3">

        <a class="navbar-brand" href="{{ route('panel') }}">
            <img src="{{ asset('assets/imagotipo.svg') }}" alt="{{ config('app.name') }}" height="40">
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain"
            aria-controls="navbarMain" aria-expanded="false" aria-label="Abrir menú">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-3">
                <li class="nav-item">
                    <a class="nav-link text-black {{ request()->routeIs('panel') ? 'active' : '' }}"
                        href="{{ route('panel') }}">Inicio</a>
                </li>
                @can('rol.ver')
                    <li class="nav-item">
                        <a class="nav-link text-black {{ request()->routeIs('roles.*') ? 'active' : '' }}"
                            href="{{ route('roles.index') }}">Roles</a>
                    </li>
                @endcan
                {{-- Cada módulo se suma acá dentro de su @can, a medida que existe --}}
            </ul>

            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-black" href="#" role="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> {{ auth()->user()->nombre }}
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text small text-body-secondary">
                                {{ auth()->user()->rol->nombre }}
                            </span>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="bi bi-person"></i> Mis datos
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
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
