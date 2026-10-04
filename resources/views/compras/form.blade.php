@extends('layouts.app')

@php
    $esEdicion = $orden->exists;

    // De dónde salen las líneas que se muestran: lo que el usuario mandó si hubo
    // un error de validación, y si no, lo guardado. Las claves no tienen que ser
    // consecutivas —PHP recibe lineas[0] y lineas[7] como dos elementos— así que
    // agregar y quitar filas no obliga a renumerar nada.
    $lineasCargadas = old(
        'lineas',
        $esEdicion
            ? $orden->lineas
                ->map(
                    fn($linea) => [
                        'producto_id' => $linea->producto_id,
                        'cantidad_pedida' => $linea->cantidad_pedida,
                        'costo_unitario' => $linea->costo_unitario,
                    ],
                )
                ->all()
            : [],
    );

    $proximoIndice = $lineasCargadas === [] ? 0 : max(array_keys($lineasCargadas)) + 1;
@endphp

@section('title', $esEdicion ? 'Editar orden de compra' : 'Nueva orden de compra')

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">
            {{ $esEdicion ? "Editar la orden {$orden->numeroFormateado()}" : 'Nueva orden de compra' }}
        </h1>
        <p class="text-body-secondary small mb-0">
            Se guarda en borrador. Mientras esté en borrador se puede modificar; una vez aprobada,
            el monto queda comprometido y las líneas se congelan.
        </p>
    </div>

    @include('partials.errores')

    <form method="POST" novalidate action="{{ $esEdicion ? route('compras.update', $orden) : route('compras.store') }}">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        <div class="card card-body border-0 shadow-sm mb-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="proveedor_id" class="form-label">
                        Proveedor <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('proveedor_id') is-invalid @enderror" id="proveedor_id"
                        name="proveedor_id" data-buscable required
                        aria-describedby="ayudaProveedorOrden @error('proveedor_id') errorProveedorOrden @enderror">
                        <option value="">Elegí un proveedor</option>
                        @foreach ($proveedores as $opcion)
                            <option value="{{ $opcion->id }}" @selected((string) old('proveedor_id', $orden->proveedor_id) === (string) $opcion->id)>
                                {{ $opcion->razon_social }}
                            </option>
                        @endforeach
                    </select>
                    <div id="ayudaProveedorOrden" class="form-text">
                        Sólo se ofrecen los proveedores activos.
                    </div>
                    @error('proveedor_id')
                        <div id="errorProveedorOrden" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-6">
                    <label for="observaciones" class="form-label">Observaciones</label>
                    <textarea class="form-control @error('observaciones') is-invalid @enderror" id="observaciones" name="observaciones"
                        rows="2" maxlength="255" aria-describedby="ayudaObs @error('observaciones') errorObs @enderror">{{ old('observaciones', $orden->observaciones) }}</textarea>
                    <div id="ayudaObs" class="form-text">Van impresas en el pedido que recibe el proveedor.</div>
                    @error('observaciones')
                        <div id="errorObs" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Productos del pedido</h2>
                        <p class="text-body-secondary small mb-0">
                            Un producto por línea. El costo se sugiere con el último que se pagó;
                            escribilo si cambió.
                        </p>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-dark" data-agregar-linea>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar producto
                    </button>
                </div>

                @error('lineas')
                    <div class="alert alert-danger">{{ $message }}</div>
                @enderror

                <table class="table align-middle mb-0" data-lineas-orden data-proximo-indice="{{ $proximoIndice }}">
                    <caption class="visually-hidden">Productos de la orden de compra</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Producto</th>
                            <th scope="col" class="text-end">Cantidad</th>
                            <th scope="col" class="text-end">Costo unitario</th>
                            <th scope="col" class="text-end">Subtotal</th>
                            <th scope="col"><span class="visually-hidden">Quitar</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($lineasCargadas as $indice => $linea)
                            @include('compras._linea', [
                                'indice' => $indice,
                                'linea' => $linea,
                                'productos' => $productos,
                            ])
                        @endforeach

                        {{-- La plantilla va DENTRO del tbody: un <tr> dentro de un
                             <template> suelto no sobrevive al parseo del navegador,
                             porque una fila fuera de una tabla se descarta. Su
                             contenido vive en un fragmento aparte, así que no
                             aparece en el DOM ni en los querySelectorAll('tr'). --}}
                        <template id="plantillaLinea">
                            @include('compras._linea', [
                                'indice' => '__INDICE__',
                                'linea' => null,
                                'productos' => $productos,
                            ])
                        </template>
                    </tbody>

                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Total estimado</td>
                            <td class="text-end fw-semibold" data-total-orden>$ 0,00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas hidden>
                    La orden no tiene productos. Agregá al menos uno.
                </p>
            </div>
        </div>

        <div class="d-grid d-sm-flex gap-2">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear orden en borrador' }}
            </button>

            <a href="{{ route('compras.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
