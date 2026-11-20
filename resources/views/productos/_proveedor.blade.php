{{--
    El vínculo de un producto con un proveedor, como shell (tramo D). Alta y edición comparten este
    formulario: el alta lo muestra el comparador y la edición su propia pantalla. Los valores, las
    opciones y la ayuda de la preferencia los pone la pantalla con lo que trae la API.

    Parámetros: $productoId, $esEdicion; en la edición, $proveedorId.
--}}
<form novalidate data-formulario-vinculo data-producto="{{ $productoId }}"
    @if ($esEdicion) data-proveedor="{{ $proveedorId }}" data-volver="{{ route('producto-proveedores.index', $productoId) }}" @endif>
    <p class="text-danger small" data-errores-formulario hidden></p>

    <div class="row g-3">
        <div class="col-12 col-md-5">
            @if ($esEdicion)
                {{-- En la edición el proveedor no es un campo: lo determina la URL, y la API lo
                     declara prohibido en el cuerpo. --}}
                <div class="form-label">Proveedor</div>
                <p class="form-control-plaintext fw-semibold mb-0" data-dato="proveedor"></p>
                <div class="form-text">
                    No se cambia por otro: si te equivocaste, quitá este vínculo y cargá el que va.
                </div>
            @else
                <label for="proveedor_id" class="form-label">
                    Proveedor <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select" id="proveedor_id" name="proveedor_id" data-buscable data-buscable-diferido>
                    <option value="">Elegí el proveedor</option>
                </select>
            @endif
        </div>

        <div class="col-6 col-md-3">
            <label for="costo_ultimo" class="form-label">Último costo</label>
            {{-- Texto y no type="number": un campo numérico del navegador reinterpreta "25.000,50"
                 según el idioma del sistema. --}}
            <div class="input-group">
                <span class="input-group-text" aria-hidden="true">$</span>
                <input type="text" inputmode="decimal" autocomplete="off" class="form-control text-end"
                    id="costo_ultimo" name="costo_ultimo" aria-describedby="ayudaCosto">
            </div>
            <div id="ayudaCosto" class="form-text">Vacío si todavía no lo sabés.</div>
        </div>

        <div class="col-6 col-md-4">
            <label for="codigo_proveedor" class="form-label">Su código</label>
            <input type="text" class="form-control" id="codigo_proveedor" name="codigo_proveedor" maxlength="40"
                aria-describedby="ayudaCodigo">
            <div id="ayudaCodigo" class="form-text">
                Con el que el proveedor identifica el producto. Va impreso en el pedido.
            </div>
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="es_preferido" name="es_preferido"
                    aria-describedby="ayudaPreferido">
                <label class="form-check-label" for="es_preferido">
                    Pedirle la reposición a este proveedor
                </label>
                <div id="ayudaPreferido" class="form-text" data-ayuda-preferido>
                    Es a quien le va a pedir la reposición automática. Marcarlo desmarca al que lo sea hoy.
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-acento">
            <i class="bi bi-check-lg" aria-hidden="true"></i>
            {{ $esEdicion ? 'Guardar cambios' : 'Agregar proveedor' }}
        </button>

        @if ($esEdicion)
            <a href="{{ route('producto-proveedores.index', $productoId) }}" class="btn btn-outline-secondary">
                Cancelar
            </a>
        @endif
    </div>
</form>
