@if (session('exito'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('exito') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif

{{-- Ni éxito ni error: la operación corrió y no hizo falta hacer nada, o no
     correspondía. Un verde diría que pasó algo que no pasó. --}}
@if (session('aviso'))
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        {{ session('aviso') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif
