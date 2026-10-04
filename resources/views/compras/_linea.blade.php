{{--
    Una línea de la orden.

    Parámetros: $indice (número o '__INDICE__'), $linea (arreglo o null), $productos.
--}}
@use('App\Support\Importe')
<tr>
    <td>
        <label class="visually-hidden" for="producto_{{ $indice }}">Producto</label>
        <select class="form-select form-select-sm @error("lineas.{$indice}.producto_id") is-invalid @enderror"
            id="producto_{{ $indice }}" name="lineas[{{ $indice }}][producto_id]" data-buscable required
            data-producto>
            <option value="">Elegí un producto</option>
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

    <td style="width: 7rem;">
        <label class="visually-hidden" for="cantidad_{{ $indice }}">Cantidad</label>
        <input type="number"
            class="form-control form-control-sm text-end @error("lineas.{$indice}.cantidad_pedida") is-invalid @enderror"
            id="cantidad_{{ $indice }}" name="lineas[{{ $indice }}][cantidad_pedida]"
            value="{{ $linea['cantidad_pedida'] ?? '' }}" min="1" max="100000" step="1" required
            data-cantidad>

        @error("lineas.{$indice}.cantidad_pedida")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td style="width: 10rem;">
        <label class="visually-hidden" for="costo_{{ $indice }}">Costo unitario</label>
        {{-- Texto y no type="number": un campo numérico del navegador reinterpreta
             "25.000,50" según el idioma del sistema. --}}
        <div class="input-group input-group-sm">
            <span class="input-group-text" aria-hidden="true">$</span>
            <input type="text" inputmode="decimal" autocomplete="off"
                class="form-control text-end @error("lineas.{$indice}.costo_unitario") is-invalid @enderror"
                id="costo_{{ $indice }}" name="lineas[{{ $indice }}][costo_unitario]"
                value="{{ Importe::paraFormulario($linea['costo_unitario'] ?? null) }}" required data-costo-unitario>
        </div>

        @error("lineas.{$indice}.costo_unitario")
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </td>

    <td class="text-end text-nowrap align-middle" data-subtotal>—</td>

    <td class="text-end align-middle">
        <button type="button" class="btn btn-sm btn-outline-danger" data-quitar-linea aria-label="Quitar esta línea">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </td>
</tr>
