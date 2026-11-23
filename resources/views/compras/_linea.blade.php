{{--
    Una línea de la orden: el producto ya elegido, la cantidad y el costo. Es sólo la plantilla (tramo G):
    las filas las arma el JS con lo que trae `GET /api/v1/compras/{orden}` o con lo que se elige en el
    buscador, y los `name` los pone al mandar, en el orden en que viajan, para que un 422 caiga en su renglón.

    Parámetro: $indice ('__INDICE__').
--}}
<tr data-linea>
    <td style="min-width: 14rem;">
        {{-- El producto es texto fijo: se eligió en el buscador de arriba y acá no se
             cambia, se quita el renglón. El id viaja en un campo oculto. --}}
        <input type="hidden" data-producto data-campo-linea="producto_id">

        <span class="fw-semibold" data-rotulo>—</span>
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        {{-- Con diez renglones, «Cantidad» diez veces no le dice nada a un lector de
             pantalla: el JS le agrega el producto. --}}
        <label class="visually-hidden" for="cantidad_{{ $indice }}" data-rotulo-campo="Cantidad">Cantidad</label>
        <input type="number" inputmode="numeric" class="form-control form-control-sm text-end"
            id="cantidad_{{ $indice }}" min="1" max="100000" step="1" required
            data-cantidad data-campo-linea="cantidad_pedida">
    </td>

    <td style="width: 9.5rem; min-width: 8.5rem;">
        <label class="visually-hidden" for="costo_{{ $indice }}" data-rotulo-campo="Costo unitario">Costo unitario</label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-sm text-end"
                id="costo_{{ $indice }}" required data-costo-unitario data-campo-linea="costo_unitario">
        </div>
    </td>

    <td class="text-end text-nowrap align-middle" style="width: 7.5rem; min-width: 6rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button"
            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar esta línea de la orden" style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
