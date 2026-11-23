{{--
    Una línea del armador: producto, proveedor, cantidad y costo. Es sólo la plantilla (tramo G): las
    filas las arma el JS, con lo que trae la API, y los `name` los pone al mandar, en el orden en que
    viajan, para que un 422 caiga en su renglón. Los proveedores de cada producto los pide a la API.
--}}
<tr data-linea>
    <td style="min-width: 14rem;">
        <label class="visually-hidden" for="pedido_producto_{{ $indice }}">Producto</label>
        <select class="form-select form-select-sm" id="pedido_producto_{{ $indice }}" required
            data-producto data-campo-linea="producto_id" data-buscable
            data-buscable-url="/productos?forma=gestion&amp;estado=activos&amp;con_proveedor=1"
            data-buscable-minimo="2" data-buscable-detalle="disponible">
            <option value="">Elegí un producto</option>
        </select>
    </td>

    <td style="width: 15rem; min-width: 12rem;">
        <label class="visually-hidden" for="pedido_proveedor_{{ $indice }}">Proveedor</label>
        <select class="form-select form-select-sm" id="pedido_proveedor_{{ $indice }}" required
            data-proveedor data-campo-linea="proveedor_id">
            <option value="">Elegí primero el producto</option>
        </select>
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="pedido_cantidad_{{ $indice }}">Cantidad</label>
        <input type="number" inputmode="numeric" class="form-control form-control-sm text-end"
            id="pedido_cantidad_{{ $indice }}" min="1" max="100000" step="1" required
            data-cantidad data-campo-linea="cantidad_pedida">
    </td>

    <td style="width: 9.5rem; min-width: 8.5rem;">
        <label class="visually-hidden" for="pedido_costo_{{ $indice }}">Costo unitario</label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-sm text-end"
                id="pedido_costo_{{ $indice }}" required data-costo-unitario data-campo-linea="costo_unitario">
        </div>
    </td>

    <td class="text-end text-nowrap align-middle" style="width: 7.5rem; min-width: 6rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button"
            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar esta línea del pedido" style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
