{{--
    El vínculo de un producto con un proveedor. Alta y edición comparten este
    formulario: el alta lo muestra el comparador y la edición su propia pantalla.

    Parámetros: $producto, $vinculo (Proveedor con pivot, o null en el alta),
                $disponibles (sólo en el alta), $esElPrimero.
--}}
@use('App\Support\Importe')

@php $esEdicion = $vinculo !== null; @endphp

<form method="POST" novalidate
    action="{{ $esEdicion
        ? route('producto-proveedores.update', [$producto, $vinculo])
        : route('producto-proveedores.store', $producto) }}">
    @csrf
    @if ($esEdicion)
        @method('PUT')
    @endif

    <div class="row g-3">
        <div class="col-12 col-md-5">
            @if ($esEdicion)
                {{-- En la edición el proveedor no es un campo: lo determina la URL,
                     y ProductoProveedorRequest lo declara prohibido en el cuerpo. --}}
                <div class="form-label">Proveedor</div>
                <p class="form-control-plaintext fw-semibold mb-0">{{ $vinculo->razon_social }}</p>
                <div class="form-text">
                    No se cambia por otro: si te equivocaste, quitá este vínculo y cargá el que va.
                </div>
            @else
                <label for="proveedor_id" class="form-label">
                    Proveedor <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <select class="form-select @error('proveedor_id') is-invalid @enderror" id="proveedor_id"
                    name="proveedor_id" data-buscable
                    aria-describedby="@error('proveedor_id') errorProveedor @enderror">
                    <option value="">Elegí el proveedor</option>
                    @foreach ($disponibles as $opcion)
                        <option value="{{ $opcion->id }}" @selected((string) old('proveedor_id') === (string) $opcion->id)>
                            {{ $opcion->razon_social }}
                        </option>
                    @endforeach
                </select>
                @error('proveedor_id')
                    <div id="errorProveedor" class="invalid-feedback">{{ $message }}</div>
                @enderror
            @endif
        </div>

        <div class="col-6 col-md-3">
            <label for="costo_ultimo" class="form-label">Último costo</label>
            {{-- Texto y no type="number": un campo numérico del navegador
                 reinterpreta "25.000,50" según el idioma del sistema. --}}
            <div class="input-group">
                <span class="input-group-text" aria-hidden="true">$</span>
                <input type="text" inputmode="decimal" autocomplete="off"
                    class="form-control text-end @error('costo_ultimo') is-invalid @enderror" id="costo_ultimo"
                    name="costo_ultimo"
                    value="{{ Importe::paraFormulario(old('costo_ultimo', $vinculo?->pivot->costo_ultimo)) }}"
                    aria-describedby="ayudaCosto @error('costo_ultimo') errorCosto @enderror">
            </div>
            <div id="ayudaCosto" class="form-text">Vacío si todavía no lo sabés.</div>
            @error('costo_ultimo')
                <div id="errorCosto" class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-6 col-md-4">
            <label for="codigo_proveedor" class="form-label">Su código</label>
            <input type="text" class="form-control @error('codigo_proveedor') is-invalid @enderror"
                id="codigo_proveedor" name="codigo_proveedor" maxlength="40"
                value="{{ old('codigo_proveedor', $vinculo?->pivot->codigo_proveedor) }}"
                aria-describedby="ayudaCodigo @error('codigo_proveedor') errorCodigo @enderror">
            <div id="ayudaCodigo" class="form-text">
                Con el que el proveedor identifica el producto. Va impreso en el pedido.
            </div>
            @error('codigo_proveedor')
                <div id="errorCodigo" class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-12">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="es_preferido" name="es_preferido"
                    @checked(old('es_preferido', $vinculo?->pivot->es_preferido)) aria-describedby="ayudaPreferido">
                <label class="form-check-label" for="es_preferido">
                    Pedirle la reposición a este proveedor
                </label>

                <div id="ayudaPreferido" class="form-text">
                    @if (!$esEdicion && $esElPrimero)
                        Es el primer proveedor de este producto, así que va a quedar elegido.
                    @elseif ($esEdicion && $vinculo->pivot->es_preferido)
                        Hoy es el elegido. Destildar esto no lo cambia: para cambiarlo, marcá otro.
                    @else
                        Es a quien le va a pedir la reposición automática. Marcarlo desmarca al que lo sea hoy.
                    @endif
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
            <a href="{{ route('producto-proveedores.index', $producto) }}" class="btn btn-outline-secondary">
                Cancelar
            </a>
        @endif
    </div>
</form>
