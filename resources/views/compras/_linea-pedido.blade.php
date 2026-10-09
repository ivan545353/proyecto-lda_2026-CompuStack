{{--
    Una línea del armador: producto, proveedor, cantidad y costo.

    Parámetros: $indice (número o '__INDICE__'), $linea (arreglo o null),
                $productos, $proveedores.

    El selector de PRODUCTO es buscable: la lista es larga. El de PROVEEDOR no, y es
    una decisión: tiene dos o tres opciones y el JS lo reconstruye cada vez que
    cambia el producto, y Tom Select no permite reemplazar sus opciones al vuelo

    Mejora progresiva: el servidor renderiza el selector de proveedor con TODOS los
    activos. Con JS se filtra a los que proveen ese producto; sin JS queda completo y
    el servidor valida el par. Se pierde el filtro, no la pantalla.
--}}
@use('App\Support\Importe')
<tr data-linea>
    <td style="min-width: 14rem;">
        <label class="visually-hidden" for="pedido_producto_{{ $indice }}">Producto</label>
        <select class="form-select form-select-sm @error("lineas.{$indice}.producto_id") is-invalid @enderror"
            id="pedido_producto_{{ $indice }}" name="lineas[{{ $indice }}][producto_id]" data-buscable
            required data-producto>
            <option value="">Elegí un producto</option>
            @foreach ($productos as $opcion)
                <option value="{{ $opcion->id }}" @selected((string) ($linea['producto_id'] ?? '') === (string) $opcion->id)>
                    {{ $opcion->codigo }} — {{ $opcion->nombre }}
                </option>
            @endforeach
        </select>

        @error("lineas.{$indice}.producto_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 15rem; min-width: 12rem;">
        <label class="visually-hidden" for="pedido_proveedor_{{ $indice }}">Proveedor</label>
        <select class="form-select form-select-sm @error("lineas.{$indice}.proveedor_id") is-invalid @enderror"
            id="pedido_proveedor_{{ $indice }}" name="lineas[{{ $indice }}][proveedor_id]" required
            data-proveedor>
            <option value="">Elegí el proveedor</option>
            @foreach ($proveedores as $opcion)
                <option value="{{ $opcion->id }}" @selected((string) ($linea['proveedor_id'] ?? '') === (string) $opcion->id)>
                    {{ $opcion->razon_social }}
                </option>
            @endforeach
        </select>

        @error("lineas.{$indice}.proveedor_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="pedido_cantidad_{{ $indice }}">Cantidad</label>
        <input type="number" inputmode="numeric"
            class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad_pedida") is-invalid @enderror"
            id="pedido_cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad_pedida]"
            value="{{ $linea['cantidad_pedida'] ?? '' }}" min="1" max="100000" step="1" required
            data-cantidad>

        @error("lineas.{$indice}.cantidad_pedida")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 9.5rem; min-width: 8.5rem;">
        <label class="visually-hidden" for="pedido_costo_{{ $indice }}">Costo unitario</label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off"
                class="form-control form-control-sm text-end @error("lineas.{$indice}.costo_unitario") is-invalid @enderror"
                id="pedido_costo_{{ $indice }}" name="lineas[{{ $indice }}][costo_unitario]"
                value="{{ Importe::paraFormulario($linea['costo_unitario'] ?? null) }}" required data-costo-unitario>
        </div>

        @error("lineas.{$indice}.costo_unitario")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td class="text-end text-nowrap align-middle" style="width: 7.5rem; min-width: 6rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar esta línea del pedido" style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
