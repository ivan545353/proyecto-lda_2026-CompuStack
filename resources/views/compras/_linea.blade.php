{{--
    Una línea de la orden.

    Parámetros: $indice (número o '__INDICE__'), $linea (arreglo o null), $productos.
--}}
@use('App\Support\Importe')
<tr>
    <td style="min-width: 14rem;">
        <label class="visually-hidden" for="producto_{{ $indice }}">Producto</label>
        <select class="form-select form-select-sm @error("lineas.{$indice}.producto_id") is-invalid @enderror"
            id="producto_{{ $indice }}" name="lineas[{{ $indice }}][producto_id]" data-buscable required
            data-producto>
            <option value="">Elegí un producto</option>
            {{-- El costo que se sugiere es `costo_promedio`: el promedio ponderado de
                 todas las compras del producto, a todos los proveedores. Alcanza como
                 sugerencia, pero no es lo último que cobró el proveedor al que se le
                 va a pedir. Ese dato es `producto_proveedor.costo_ultimo`, y recién
                 se puede usar cuando cada línea tenga su proveedor: paso 5 del plan
                 de varios proveedores. El requisito «el costo unitario se
                 autocompleta con el costo anterior» queda cerrado ahí, no antes. --}}
            @foreach ($productos as $opcion)
                <option value="{{ $opcion->id }}" data-costo-sugerido="{{ $opcion->costo_promedio }}"
                    @selected((string) ($linea['producto_id'] ?? '') === (string) $opcion->id)>
                    {{ $opcion->codigo }} — {{ $opcion->nombre }}
                </option>
            @endforeach
        </select>

        @error("lineas.{$indice}.producto_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="cantidad_{{ $indice }}">Cantidad</label>
        <input type="number" inputmode="numeric"
            class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad_pedida") is-invalid @enderror"
            id="cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad_pedida]"
            value="{{ $linea['cantidad_pedida'] ?? '' }}" min="1" max="100000" step="1" required
            data-cantidad>

        @error("lineas.{$indice}.cantidad_pedida")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 9.5rem; min-width: 8.5rem;">
        <label class="visually-hidden" for="costo_{{ $indice }}">Costo unitario</label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off"
                class="form-control form-control-sm text-end @error("lineas.{$indice}.costo_unitario") is-invalid @enderror"
                id="costo_{{ $indice }}" name="lineas[{{ $indice }}][costo_unitario]"
                value="{{ Importe::paraFormulario($linea['costo_unitario'] ?? null) }}" required data-costo-unitario>
        </div>

        @error("lineas.{$indice}.costo_unitario")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td class="text-end text-nowrap align-middle" style="width: 7.5rem; min-width: 6rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar esta línea" style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
