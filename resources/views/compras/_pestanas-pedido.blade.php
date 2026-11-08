{{-- Las dos formas de armar un pedido. Son enlaces y no pestañas de JavaScript: cada
     una es su propia pantalla, y es lo que deja que el armador no cargue en su HTML una
     lista que crece con el catálogo. --}}
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ $activa === 'sugeridos' ? 'active' : 'link-dark' }}" href="{{ route('compras.sugeridos') }}"
            @if ($activa === 'sugeridos') aria-current="page" @endif>
            Sugeridos
        </a>
    </li>

    <li class="nav-item">
        <a class="nav-link {{ $activa === 'armar' ? 'active' : 'link-dark' }}" href="{{ route('compras.pedido') }}"
            @if ($activa === 'armar') aria-current="page" @endif>
            Armar a mano
        </a>
    </li>
</ul>
