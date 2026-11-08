{{-- Un número con su rótulo. $nota es la aclaración que evita que el número se lea mal.
     Opcionales: $comparacion (con $periodo y $anterior), y $enlace con su $permiso y su
     $rotulo, que sólo se dibuja para quien puede ver el destino; sin permiso, el número
     se ve igual y no es enlace. $detalle y $detalleEnlace son un segundo número del mismo
     destino, bajo el mismo permiso. --}}
<div class="{{ $ancho ?? 'col-12 col-sm-6 col-xl-3' }}">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <p class="text-body-secondary small text-uppercase mb-1">{{ $titulo }}</p>

            @isset($enlace)
                @can($permiso)
                    <a href="{{ $enlace }}" class="h4 mb-1 d-inline-block link-dark link-underline-opacity-25"
                        aria-label="{{ $rotulo }}" data-atencion>{{ $valor }}</a>
                @else
                    <p class="h4 mb-1">{{ $valor }}</p>
                @endcan
            @else
                <p class="h4 mb-1">{{ $valor }}</p>
            @endisset

            @isset($comparacion)
                <p class="small mb-1" data-comparacion="{{ $comparacion->sentido }}">
                    @if ($comparacion->icono())
                        <i class="bi {{ $comparacion->icono() }}" aria-hidden="true"></i>
                    @endif
                    {{ $comparacion->texto($periodo, $anterior) }}
                </p>
            @endisset

            @isset($nota)
                <p class="text-body-secondary small mb-0">{{ $nota }}</p>
            @endisset

            @isset($detalle)
                <p class="small mb-0 mt-1">
                    @can($permiso)
                        <a href="{{ $detalleEnlace }}" class="link-dark" data-atencion>{{ $detalle }}</a>
                    @else
                        {{ $detalle }}
                    @endcan
                </p>
            @endisset
        </div>
    </div>
</div>
