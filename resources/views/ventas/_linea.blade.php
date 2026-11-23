{{--
    La plantilla de un renglón de la venta (tramo H): el producto ya elegido, la cantidad y el precio que
    pone el servidor. No lleva datos: el JS la clona con lo que trae el buscador o la ficha, reemplaza
    __INDICE__ en los id y pone los `name` al mandar (`lineas.{i}.campo`, en el orden en que viajan), así
    un 422 cae en su renglón. El precio de hoy y el disponible viajan en los data- de la fila.
--}}
<tr data-linea data-precio-hoy="" data-disponible="">
    <td style="min-width: 15rem;">
        {{-- El producto es texto fijo: se eligió en el buscador de arriba y acá no se cambia, se quita el
             renglón. El id viaja en un campo oculto. --}}
        <input type="hidden" value="" data-producto data-campo-linea="producto_id">

        <span class="fw-semibold" data-rotulo>—</span>
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="venta_cantidad___INDICE__" data-rotulo-cantidad>Cantidad de esta línea</label>
        <input type="number" inputmode="numeric" class="form-control form-control-sm text-end"
            id="venta_cantidad___INDICE__" value="" min="1" max="100000" step="1" required
            data-cantidad data-campo-linea="cantidad" aria-describedby="venta_stock___INDICE__">

        <div class="small text-warning-emphasis mt-1" id="venta_stock___INDICE__" data-aviso-stock hidden></div>
    </td>

    <td class="text-end align-middle text-nowrap" style="width: 9.5rem; min-width: 8rem;">
        <span data-precio>—</span>

        <div class="small text-body-secondary" data-nota-precio hidden>
            precio de cuando se cotizó
        </div>
    </td>

    <td class="text-end align-middle text-nowrap" style="width: 8rem; min-width: 6.5rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button"
            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar esta línea de la venta"
            style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
