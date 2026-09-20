@if ($errors->any())
    <div class="alert alert-danger d-flex gap-3" role="alert" tabindex="-1" id="resumenErrores">
        <i class="bi bi-exclamation-triangle-fill fs-5" aria-hidden="true"></i>

        <div>
            <p class="fw-semibold mb-1">
                No se pudo guardar. Revisá {{ $errors->count() === 1 ? 'este punto' : 'estos puntos' }}:
            </p>

            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
