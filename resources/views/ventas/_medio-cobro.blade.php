{{-- Un medio de pago del cobro. Recibe: $indice, $clave, $valor (lo que rinde el value),
     $propuesto (si el valor lo propuso la pantalla), $autofocus y $ayuda. --}}
@use('App\Models\Pago')

@php
    $campo = "pagos.{$indice}.monto";
    $tieneError = $errors->has($campo);
@endphp

<div class="col-12 col-sm-6">
    <input type="hidden" name="pagos[{{ $indice }}][metodo]" value="{{ $clave }}">

    <label for="monto-{{ $clave }}" class="form-label">
        {{ Pago::METODOS[$clave] }}
    </label>

    <div class="input-group">
        <span class="input-group-text" aria-hidden="true">$</span>

        {{-- Campo de dinero: `text` con `inputmode="decimal"`, nunca `number`. --}}
        <input type="text" inputmode="decimal" class="form-control @error($campo) is-invalid @enderror"
            id="monto-{{ $clave }}" name="pagos[{{ $indice }}][monto]" value="{{ $valor }}"
            placeholder="0,00" data-cobro-monto="{{ Pago::METODOS[$clave] }}"
            @if ($propuesto) data-cobro-propuesto @endif
            @if ($autofocus) autofocus @endif
            aria-describedby="ayuda-{{ $clave }}@if ($tieneError) error-{{ $clave }} @endif">

        {{-- Sólo funciona con JavaScript, así que nace oculto y lo muestra cobro.js. --}}
        <button type="button" class="btn btn-outline-secondary" data-cobro-total hidden
            aria-label="Cobrar todo el saldo por {{ Pago::METODOS[$clave] }}">
            Total
        </button>
    </div>

    <div id="ayuda-{{ $clave }}" class="form-text">{{ $ayuda }}</div>

    @error($campo)
        <div id="error-{{ $clave }}" class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
