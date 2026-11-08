@extends('layouts.app')

@use('App\Http\Requests\BusquedaRequest')

@php
    // El proveedor del borrador: recorta qué productos ofrece el buscador, porque
    // no se le pide a alguien algo que no vende.
    $proveedorElegido = old('proveedor_id', $orden->proveedor_id);

    // Lo que el buscador no vuelve a ofrecer. Sale de las filas que rinde el
    // servidor, así el primer tecleo ya excluye sin esperar al JavaScript.
    $idsCargados = collect($lineas)->pluck('producto_id')->filter(fn($id) => ctype_digit((string) $id))->implode(',');
@endphp

@section('title', 'Editar orden de compra')

@section('content')
    <a href="{{ route('compras.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">
            Editar la orden {{ $orden->numeroFormateado() }}
        </h1>
        <p class="text-body-secondary small mb-0">
            Se guarda en borrador. Mientras esté en borrador se puede modificar; una vez aprobada,
            el monto queda comprometido y las líneas se congelan.
        </p>
    </div>

    @include('partials.errores')

    {{-- La única advertencia de la pantalla, y sólo existe si el JS no corre. --}}
    <noscript>
        <div class="alert alert-warning d-flex gap-2" role="alert">
            <i class="bi bi-exclamation-triangle flex-shrink-0 mt-1" aria-hidden="true"></i>
            <div>
                <strong>Sin JavaScript no se pueden agregar productos en esta pantalla.</strong>
                El buscador le pregunta al servidor mientras escribís, porque mandar el
                catálogo en cada renglón hacía crecer la página con el tamaño del catálogo.
                Lo que sí funciona acá: el selector de proveedor, que viene completo, y los
                renglones que la orden ya tiene, con sus cantidades y sus costos.
            </div>
        </div>
    </noscript>

    <form method="POST" novalidate action="{{ route('compras.update', $orden) }}">
        @csrf
        @method('PUT')

        <div class="card card-body border-0 shadow-sm mb-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="proveedor_id" class="form-label">
                        Proveedor <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select class="form-select @error('proveedor_id') is-invalid @enderror" id="proveedor_id"
                        name="proveedor_id" data-buscable required data-proveedor-orden
                        aria-describedby="ayudaProveedorOrden @error('proveedor_id') errorProveedorOrden @enderror">
                        <option value="">Elegí un proveedor</option>
                        @foreach ($proveedores as $opcion)
                            <option value="{{ $opcion->id }}" @selected((string) $proveedorElegido === (string) $opcion->id)>
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
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h2 class="h5 mb-0">Productos del pedido</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="Un producto por línea. El costo sugiere el último registrado; podés modificarlo si varió."
                        aria-label="Información sobre productos y costos del pedido">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                {{-- El buscador ES el gesto de agregar: se escribe, se elige, y el
                     renglón aparece con el producto puesto, la cantidad en 1 y el
                     último costo de ese proveedor si el servidor lo mandó. --}}
                <div class="row g-3 mb-3">
                    <div class="col-12 col-lg-7">
                        <label for="buscadorProducto" class="form-label">Agregar un producto</label>
                        <select id="buscadorProducto" class="form-select" data-buscador-producto data-buscable
                            data-buscable-url="{{ route('busqueda.productos', ['proveedor_id' => $proveedorElegido]) }}"
                            data-buscable-minimo="{{ BusquedaRequest::MINIMO }}" data-buscable-detalle="disponible"
                            data-buscable-excluir="{{ $idsCargados }}" aria-describedby="ayudaBuscadorProducto">
                            <option value="">Buscá por código o nombre</option>
                        </select>

                        <div id="ayudaBuscadorProducto" class="form-text">
                            Sólo los productos que provee el proveedor elegido. Ej: TEC-00123 o «teclado».
                        </div>

                        <div class="form-text text-warning-emphasis" data-falta-proveedor
                            @if ($proveedorElegido) hidden @endif>
                            Elegí primero el proveedor: no se le puede pedir a alguien algo que no vende.
                        </div>
                    </div>
                </div>

                @error('lineas')
                    <div class="alert alert-danger">{{ $message }}</div>
                @enderror

                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-lineas-orden data-proximo-indice="{{ $proximoIndice }}"
                        data-productos-url="{{ route('busqueda.productos', ['proveedor_id' => '__PROVEEDOR__']) }}"
                        style="min-width: 40rem;">
                        <caption class="visually-hidden">Productos de la orden de compra</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col" style="min-width: 14rem;">Producto</th>
                                <th scope="col" class="text-end" style="width: 7rem; min-width: 5.5rem;">Cantidad</th>
                                <th scope="col" class="text-end" style="width: 9.5rem; min-width: 8.5rem;">Costo unitario
                                </th>
                                <th scope="col" class="text-end" style="width: 7.5rem; min-width: 6rem;">Subtotal</th>
                                <th scope="col" style="width: 3.5rem; min-width: 3rem;">
                                    <span class="visually-hidden">Quitar</span>
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($lineas as $indice => $linea)
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
                </div>

                <p class="text-body-secondary small mt-3 mb-0" data-sin-lineas
                    @if (count($lineas) > 0) hidden @endif>
                    Todavía no hay ningún producto. Buscalo arriba por código o por nombre.
                </p>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('compras.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                Guardar cambios
            </button>
        </div>
    </form>
@endsection
