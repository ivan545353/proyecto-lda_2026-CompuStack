{{-- Un medio de pago del cobro (tramo H): nace vacío y con la ayuda de un medio adicional. Si es el
     principal, el JS lo mueve arriba, le cambia la ayuda y le propone el saldo de la ficha. El `name` es
     el de la clave de error de la API (`pagos.{i}.monto`), así un 422 cae en su campo.
     Recibe: $indice, $clave. --}}
@use('App\Models\Pago')

<div class="col-12 col-sm-6" data-medio="{{ $clave }}">
    <label for="monto-{{ $clave }}" class="form-label">
        {{ Pago::METODOS[$clave] }}
    </label>

    <div class="input-group">
        <span class="input-group-text" aria-hidden="true">$</span>

        {{-- Campo de dinero: `text` con `inputmode="decimal"`, nunca `number`. --}}
        <input type="text" inputmode="decimal" class="form-control"
            id="monto-{{ $clave }}" name="pagos.{{ $indice }}.monto" value=""
            placeholder="0,00" data-cobro-monto="{{ Pago::METODOS[$clave] }}" data-metodo="{{ $clave }}"
            aria-describedby="ayuda-{{ $clave }}">

        {{-- Sólo funciona con JavaScript, así que nace oculto y lo muestra cobro.js. --}}
        <button type="button" class="btn btn-outline-secondary" data-cobro-total hidden
            aria-label="Cobrar todo el saldo por {{ Pago::METODOS[$clave] }}">
            Total
        </button>
    </div>

    <div id="ayuda-{{ $clave }}" class="form-text" data-ayuda-medio>Vacío si por este medio no entró nada.</div>
</div>
