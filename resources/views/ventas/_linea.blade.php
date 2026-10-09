{{--
    Una línea de la venta: producto, cantidad, y el precio que pone el servidor.

    Parámetros: $indice (número o '__INDICE__'), $linea (arreglo o null),
                $productos, $catalogo, $congelados.

    **No hay campo de precio.** El precio lo lee el servidor del catálogo y nunca
    viene del formulario: es la nota positiva de M-14, la única cosa que el sistema
    original hacía bien en este módulo. El precio se muestra en una celda de texto, y
    `VentaRequest` declara `lineas.*.precio_unitario` como `prohibited`, así que
    mandarlo a mano no se ignora: se rechaza con un mensaje.

    El precio que se muestra es el **congelado** si ese producto ya estaba en la
    venta, y el de hoy si entra ahora. Es exactamente la regla que aplica
    `VentaService::ajustarLineas()`, y el servidor la resuelve acá con dos búsquedas
    en los mapas, sin una consulta más. El JS hace lo mismo cuando cambia el
    producto; sin JS la celda igual dice la verdad.
--}}
@use('App\Support\Importe')

@php
    $idProducto = $linea['producto_id'] ?? null;
    $cantidad = $linea['cantidad'] ?? null;

    $esCongelado = $idProducto !== null && isset($congelados[$idProducto]);
    $precioHoy = $idProducto !== null ? $catalogo[$idProducto]['precio'] ?? null : null;
    $precio = $esCongelado ? $congelados[$idProducto] : $precioHoy;

    $cambio = $esCongelado && $precioHoy !== null && (float) $precioHoy !== (float) $precio;
    $subtotal = $precio !== null && $cantidad !== null ? round((float) $precio * (int) $cantidad, 2) : null;
@endphp

<tr data-linea>
    <td style="min-width: 15rem;">
        <label class="visually-hidden" for="venta_producto_{{ $indice }}">Producto</label>
        <select class="form-select form-select-sm @error("lineas.{$indice}.producto_id") is-invalid @enderror"
            id="venta_producto_{{ $indice }}" name="lineas[{{ $indice }}][producto_id]" data-buscable required
            data-producto>
            <option value="">Elegí un producto</option>
            @foreach ($productos as $opcion)
                <option value="{{ $opcion->id }}" @selected((string) ($linea['producto_id'] ?? '') === (string) $opcion->id)>
                    {{ $opcion->codigo }} — {{ $opcion->nombre }}@unless ($opcion->activo)
                    (dado de baja)
                @endunless
            </option>
        @endforeach
    </select>

    @error("lineas.{$indice}.producto_id")
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</td>

<td style="width: 7rem; min-width: 5.5rem;">
    <label class="visually-hidden" for="venta_cantidad_{{ $indice }}">Cantidad</label>
    <input type="number" inputmode="numeric"
        class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad") is-invalid @enderror"
        id="venta_cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad]"
        value="{{ $cantidad }}" min="1" max="100000" step="1" required data-cantidad>

    @error("lineas.{$indice}.cantidad")
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror

    {{-- Avisar no es impedir: un presupuesto no compromete stock y se puede
             cotizar lo que todavía no llegó. Lo que no se va a poder es cobrarlo
             hasta que haya unidades, y eso lo rechaza StockService al descontar. --}}
    <div class="small text-warning-emphasis mt-1" data-aviso-stock hidden></div>
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
        data-quitar-linea aria-label="Quitar esta línea de la venta" style="width: 2rem; height: 2rem;">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
</td>
</tr>
