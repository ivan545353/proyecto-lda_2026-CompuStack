{{-- Filtro de período por GET: la pantalla queda enlazable y el botón "atrás" funciona. Lo que se pide a la
     API es la consulta de la URL; sin fechas, el JS pone las del período que contestó (tramo H). --}}
<form method="GET" action="{{ route($ruta) }}" class="card card-body border-0 shadow-sm mb-4" data-filtro-periodo novalidate>
    <div class="alert alert-danger" role="alert" data-errores-formulario hidden></div>

    <div class="row g-3 align-items-end">
        <div class="col-12 col-sm-5 col-lg-3">
            <label for="desde" class="form-label">Desde</label>
            <input type="date" class="form-control" id="desde" name="desde"
                value="{{ request('desde') }}" aria-describedby="ayudaPeriodo">
        </div>

        <div class="col-12 col-sm-5 col-lg-3">
            <label for="hasta" class="form-label">Hasta</label>
            <input type="date" class="form-control" id="hasta" name="hasta"
                value="{{ request('hasta') }}" aria-describedby="ayudaPeriodo">
        </div>

        <div class="col-12 col-sm-2">
            <button type="submit"
                class="btn btn-outline-dark w-100 d-inline-flex align-items-center justify-content-center gap-1">
                <i class="bi bi-funnel" aria-hidden="true"></i> Ver
            </button>
        </div>

        <div class="col-12">
            <div id="ayudaPeriodo" class="form-text">
                Las dos fechas se incluyen completas. Sin elegir nada, se muestra del primero del mes a hoy.
            </div>
        </div>
    </div>
</form>
