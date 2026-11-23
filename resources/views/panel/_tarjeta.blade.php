{{-- Un número con su rótulo (tramo H: shell). El número y su comparación los pone pantallas/panel.js con
     lo que trae la API. Recibe:
       $titulo, $campo     el rótulo y la clave del número en la respuesta (`vendi.cantidad`)
       $formato            'pesos' si el número es un importe
       $comparar           la clave de su comparación en `comparaciones`, si se compara
       $nota, $notaCampo   la aclaración que evita que se lea mal; $notaCampo es un importe que la completa
       $enlace, $permiso   el destino, que sólo se dibuja para quien puede verlo: sin permiso el número
                           se ve igual y no es enlace. $rotulo lleva __N__ donde va el número.
       $detalle            un segundo texto del mismo destino ($detalleEnlace), bajo el mismo permiso
       $ancho --}}
<div class="{{ $ancho ?? 'col-12 col-sm-6 col-xl-3' }}">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <p class="text-body-secondary small text-uppercase mb-1">{{ $titulo }}</p>

            @isset($enlace)
                @can($permiso)
                    <a href="{{ $enlace }}" class="h4 mb-1 d-inline-block link-dark link-underline-opacity-25"
                        data-rotulo="{{ $rotulo }}" data-atencion data-numero="{{ $campo }}"
                        data-formato="{{ $formato ?? 'entero' }}">—</a>
                @else
                    <p class="h4 mb-1" data-numero="{{ $campo }}" data-formato="{{ $formato ?? 'entero' }}">—</p>
                @endcan
            @else
                <p class="h4 mb-1" data-numero="{{ $campo }}" data-formato="{{ $formato ?? 'entero' }}">—</p>
            @endisset

            @isset($comparar)
                <p class="small mb-1" data-comparacion="" data-comparar="{{ $comparar }}"></p>
            @endisset

            @isset($nota)
                <p class="text-body-secondary small mb-0">
                    {{ $nota }}@isset($notaCampo) <span data-numero="{{ $notaCampo }}" data-formato="pesos"></span>.@endisset
                </p>
            @endisset

            @isset($detalle)
                <p class="small mb-0 mt-1" data-detalle hidden>
                    @can($permiso)
                        <a href="{{ $detalleEnlace }}" class="link-dark" data-atencion data-texto-detalle></a>
                    @else
                        <span data-texto-detalle></span>
                    @endcan
                </p>
            @endisset
        </div>
    </div>
</div>
