{{-- Los campos de un cliente. Los usan `clientes/form` y la ventana de alta de la venta,
     así que las dos pantallas piden lo mismo que valida ClienteRequest.
     Recibe: $cliente; $prefijo para los id (vacío en la pantalla de clientes); $autofocus. --}}
@php
    $p = $prefijo ?? '';
@endphp

<div class="row g-3">
    <div class="col-12 col-md-6">
        <label for="{{ $p }}razon_social" class="form-label">
            Nombre o razón social <span class="text-danger" aria-hidden="true">*</span>
        </label>
        <input type="text" class="form-control @error('razon_social') is-invalid @enderror"
            id="{{ $p }}razon_social" name="razon_social" maxlength="150"
            @if ($autofocus ?? true) autofocus @endif value="{{ old('razon_social', $cliente->razon_social) }}"
            aria-describedby="@error('razon_social') {{ $p }}errorRazon @enderror">
        @error('razon_social')
            <div id="{{ $p }}errorRazon" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 col-md-6">
        <div class="d-flex align-items-center gap-2 mb-2">
            <label for="{{ $p }}condicion_iva" class="form-label mb-0">
                Condición frente al IVA <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                data-bs-toggle="tooltip" data-bs-placement="top"
                data-bs-title="Responsable Inscripto lleva Factura A; el resto, Factura B."
                aria-label="Información sobre comprobantes según IVA">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
            </button>
        </div>
        <select class="form-select @error('condicion_iva') is-invalid @enderror" id="{{ $p }}condicion_iva"
            name="condicion_iva" aria-describedby="@error('condicion_iva') {{ $p }}errorIva @enderror">
            @foreach (App\Models\Cliente::CONDICIONES_IVA as $valor => $texto)
                <option value="{{ $valor }}" @selected(old('condicion_iva', $cliente->condicion_iva) === $valor)>{{ $texto }}</option>
            @endforeach
        </select>
        @error('condicion_iva')
            <div id="{{ $p }}errorIva" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="{{ $p }}tipo_doc" class="form-label">
            Tipo de documento <span class="text-danger" aria-hidden="true">*</span>
        </label>
        <select class="form-select @error('tipo_doc') is-invalid @enderror" id="{{ $p }}tipo_doc"
            name="tipo_doc" aria-describedby="@error('tipo_doc') {{ $p }}errorTipoDoc @enderror">
            @foreach (App\Models\Cliente::TIPOS_DOC as $valor => $texto)
                <option value="{{ $valor }}" @selected(old('tipo_doc', $cliente->tipo_doc) === $valor)>
                    {{ $texto }}</option>
            @endforeach
        </select>
        @error('tipo_doc')
            <div id="{{ $p }}errorTipoDoc" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="{{ $p }}nro_doc" class="form-label">Número de documento</label>
        <input type="text" inputmode="numeric" class="form-control @error('nro_doc') is-invalid @enderror"
            id="{{ $p }}nro_doc" name="nro_doc" value="{{ old('nro_doc', $cliente->nro_doc) }}"
            placeholder="20-12345678-9"
            aria-describedby="{{ $p }}ayudaDoc @error('nro_doc') {{ $p }}errorDoc @enderror">
        <div id="{{ $p }}ayudaDoc" class="form-text">
            Podés incluir guiones. Requerido para Factura A.
        </div>
        @error('nro_doc')
            <div id="{{ $p }}errorDoc" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="{{ $p }}email" class="form-label">Correo</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror" id="{{ $p }}email"
            name="email" maxlength="150" value="{{ old('email', $cliente->email) }}"
            aria-describedby="@error('email') {{ $p }}errorEmail @enderror">
        @error('email')
            <div id="{{ $p }}errorEmail" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 col-md-3">
        <label for="{{ $p }}telefono" class="form-label">Teléfono</label>
        <input type="text" class="form-control @error('telefono') is-invalid @enderror"
            id="{{ $p }}telefono" name="telefono" maxlength="30"
            value="{{ old('telefono', $cliente->telefono) }}"
            aria-describedby="@error('telefono') {{ $p }}errorTelefono @enderror">
        @error('telefono')
            <div id="{{ $p }}errorTelefono" class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
