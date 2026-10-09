{{-- Un número con su rótulo. $nota es la aclaración que evita que el número se lea mal. --}}
<div class="col-12 col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <p class="text-body-secondary small text-uppercase mb-1">{{ $titulo }}</p>
            <p class="h4 mb-1">{{ $valor }}</p>

            @isset($nota)
                <p class="text-body-secondary small mb-0">{{ $nota }}</p>
            @endisset
        </div>
    </div>
</div>
