{{--
    Una línea de la orden: el producto ya elegido, la cantidad y el costo.

    Parámetros: $indice (número o '__INDICE__'), $linea (arreglo o null), $productos.
--}}
@use('App\Support\Importe')

@php
    $idProducto = $linea['producto_id'] ?? null;
    $producto = $idProducto === null ? null : $productos[$idProducto] ?? null;

    $rotulo = $producto === null ? null : $producto->rotulo() . ($producto->activo ? '' : ' (dado de baja)');

    // Con diez renglones, «Cantidad» diez veces no le dice nada a un lector de
    // pantalla. El JavaScript escribe lo mismo en la fila que agrega.
    $nombra = $rotulo === null ? 'de esta línea' : "de {$rotulo}";
@endphp

<tr data-linea>
    <td style="min-width: 14rem;">
        {{-- El producto es texto fijo: se eligió en el buscador de arriba y acá no se
             cambia, se quita el renglón. El id viaja en un campo oculto, que es lo
             único que se manda. --}}
        <input type="hidden" name="lineas[{{ $indice }}][producto_id]" value="{{ $idProducto }}" data-producto>

        <span class="fw-semibold" data-rotulo>{{ $rotulo ?? '—' }}</span>

        @error("lineas.{$indice}.producto_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="cantidad_{{ $indice }}" data-rotulo-campo="Cantidad">
            Cantidad {{ $nombra }}
        </label>
        <input type="number" inputmode="numeric"
            class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad_pedida") is-invalid @enderror"
            id="cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad_pedida]"
            value="{{ $linea['cantidad_pedida'] ?? '' }}" min="1" max="100000" step="1" required
            data-cantidad
            @error("lineas.{$indice}.cantidad_pedida") aria-describedby="cantidad_error_{{ $indice }}" @enderror>

        @error("lineas.{$indice}.cantidad_pedida")
            <div id="cantidad_error_{{ $indice }}" class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 9.5rem; min-width: 8.5rem;">
        <label class="visually-hidden" for="costo_{{ $indice }}" data-rotulo-campo="Costo unitario">
            Costo unitario {{ $nombra }}
        </label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off"
                class="form-control form-control-sm text-end @error("lineas.{$indice}.costo_unitario") is-invalid @enderror"
                id="costo_{{ $indice }}" name="lineas[{{ $indice }}][costo_unitario]"
                value="{{ Importe::paraFormulario($linea['costo_unitario'] ?? null) }}" required data-costo-unitario
                @error("lineas.{$indice}.costo_unitario") aria-describedby="costo_error_{{ $indice }}" @enderror>
        </div>

        @error("lineas.{$indice}.costo_unitario")
            <div id="costo_error_{{ $indice }}" class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td class="text-end text-nowrap align-middle" style="width: 7.5rem; min-width: 6rem;" data-subtotal>—</td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button"
            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar {{ $rotulo ?? 'esta línea' }} de la orden"
            style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
