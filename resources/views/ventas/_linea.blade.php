{{--
    Una línea de la venta: el producto ya elegido, la cantidad, y el precio que pone
    el servidor.
--}}
@use('App\Support\Importe')

@php
    $idProducto = $linea['producto_id'] ?? null;
    $cantidad = $linea['cantidad'] ?? null;

    $producto = $idProducto === null ? null : $productos[$idProducto] ?? null;

    $esCongelado = $idProducto !== null && isset($congelados[$idProducto]);
    $precioHoy = $producto?->precio_contado;
    $precio = $esCongelado ? $congelados[$idProducto] : $precioHoy;

    $cambio = $esCongelado && $precioHoy !== null && (float) $precioHoy !== (float) $precio;
    $subtotal = $precio !== null && $cantidad !== null ? round((float) $precio * (int) $cantidad, 2) : null;

    $rotulo = $producto === null ? null : $producto->rotulo() . ($producto->activo ? '' : ' (dado de baja)');

    // Con diez renglones, «Cantidad» diez veces no le dice nada a un lector de
    // pantalla. El JavaScript escribe lo mismo en la fila que agrega.
    $nombra = $rotulo === null ? 'de esta línea' : "de {$rotulo}";
@endphp

<tr data-linea data-precio-hoy="{{ $precioHoy }}" data-disponible="{{ $producto?->stock_disponible }}">
    <td style="min-width: 15rem;">
        {{-- El producto es texto fijo: se eligió en el buscador de arriba y acá no se
             cambia, se quita el renglón. El id viaja en un campo oculto, que es lo
             único que se manda, y el precio y el disponible que contó el servidor
             viajan en los data- de la fila. --}}
        <input type="hidden" name="lineas[{{ $indice }}][producto_id]" value="{{ $idProducto }}" data-producto>

        <span class="fw-semibold" data-rotulo>{{ $rotulo ?? '—' }}</span>

        @error("lineas.{$indice}.producto_id")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 7rem; min-width: 5.5rem;">
        <label class="visually-hidden" for="venta_cantidad_{{ $indice }}" data-rotulo-cantidad>
            Cantidad {{ $nombra }}
        </label>
        <input type="number" inputmode="numeric"
            class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad") is-invalid @enderror"
            id="venta_cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad]"
            value="{{ $cantidad }}" min="1" max="100000" step="1" required data-cantidad
            aria-describedby="venta_stock_{{ $indice }} @error("lineas.{$indice}.cantidad") venta_cantidad_error_{{ $indice }} @enderror">

        @error("lineas.{$indice}.cantidad")
            <div id="venta_cantidad_error_{{ $indice }}" class="invalid-feedback d-block">{{ $message }}</div>
        @enderror

        <div class="small text-warning-emphasis mt-1" id="venta_stock_{{ $indice }}" data-aviso-stock hidden>
        </div>
    </td>

    <td class="text-end align-middle text-nowrap" style="width: 9.5rem; min-width: 8rem;">
        <span data-precio>{{ $precio === null ? '—' : Importe::pesos($precio) }}</span>

        <div class="small text-body-secondary" data-nota-precio @unless ($cambio) hidden @endunless>
            precio de cuando se cotizó
        </div>
    </td>

    <td class="text-end align-middle text-nowrap" style="width: 8rem; min-width: 6.5rem;" data-subtotal>
        {{ $subtotal === null ? '—' : Importe::pesos($subtotal) }}
    </td>

    <td class="text-center align-middle" style="width: 3.5rem; min-width: 3rem;">
        <button type="button"
            class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center"
            data-quitar-linea aria-label="Quitar {{ $rotulo ?? 'esta línea' }} de la venta"
            style="width: 2rem; height: 2rem;">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
