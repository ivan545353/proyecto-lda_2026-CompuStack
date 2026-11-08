@extends('layouts.app')

@use('App\Models\Producto')
@use('App\Support\Importe')

@php($esEdicion = $producto->exists)
@php($imagenes = $producto->imagenes ?? [])


@section('title', $esEdicion ? 'Editar producto' : 'Nuevo producto')

@section('content')
    <a href="{{ route('productos.index') }}" class="btn btn-link link-dark px-0 mb-3">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al listado
    </a>

    <div class="mb-4">
        <h1 class="h3 mb-1">{{ $esEdicion ? "Editar «{$producto->nombre}»" : 'Nuevo producto' }}</h1>
        <p class="text-body-secondary small mb-0">Los campos con * son obligatorios.</p>
    </div>

    @include('partials.errores')

    <form method="POST" enctype="multipart/form-data" novalidate
        action="{{ $esEdicion ? route('productos.update', $producto) : route('productos.store') }}">
        @csrf
        @if ($esEdicion)
            @method('PUT')
        @endif

        {{-- Identificación --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Identificación</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="codigo" class="form-label">Código <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control text-uppercase @error('codigo') is-invalid @enderror"
                        id="codigo" name="codigo" value="{{ old('codigo', $producto->codigo) }}" required maxlength="30"
                        autocomplete="off" autofocus aria-describedby="ayudaCodigo @error('codigo') errorCodigo @enderror">
                    <div id="ayudaCodigo" class="form-text">Letras, números, puntos y guiones, sin espacios.</div>
                    @error('codigo')
                        <div id="errorCodigo" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-md-8">
                    <label for="nombre" class="form-label">Nombre <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control @error('nombre') is-invalid @enderror" id="nombre"
                        name="nombre" value="{{ old('nombre', $producto->nombre) }}" required maxlength="150"
                        aria-describedby="@error('nombre') errorNombre @enderror">
                    @error('nombre')
                        <div id="errorNombre" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12">
                    <label for="descripcion" class="form-label">Descripción</label>
                    <textarea class="form-control @error('descripcion') is-invalid @enderror" id="descripcion" name="descripcion"
                        rows="3" maxlength="5000" aria-describedby="@error('descripcion') errorDescripcion @enderror">{{ old('descripcion', $producto->descripcion) }}</textarea>
                    @error('descripcion')
                        <div id="errorDescripcion" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </fieldset>

        {{-- Clasificación --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Clasificación</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="categoria_id" class="form-label">Categoría <span aria-hidden="true">*</span></label>
                    <select class="form-select @error('categoria_id') is-invalid @enderror" id="categoria_id"
                        name="categoria_id" required data-buscable="jerarquia"
                        aria-describedby="@error('categoria_id') errorCategoria @enderror">
                        <option value="">Elegí una categoría</option>
                        <x-opciones-categoria :categorias="$categorias" :seleccionada="old('categoria_id', $producto->categoria_id)" />
                    </select>
                    @error('categoria_id')
                        <div id="errorCategoria" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-sm-6 col-md-4">
                    <label for="marca_id" class="form-label">Marca</label>
                    <select class="form-select @error('marca_id') is-invalid @enderror" id="marca_id" name="marca_id"
                        data-buscable aria-describedby="@error('marca_id') errorMarca @enderror">
                        <option value="">Sin marca</option>
                        @foreach ($marcas as $opcion)
                            <option value="{{ $opcion->id }}" @selected((string) old('marca_id', $producto->marca_id) === (string) $opcion->id)>
                                {{ $opcion->nombre }}{{ $opcion->activo ? '' : ' (inactiva)' }}
                            </option>
                        @endforeach
                    </select>
                    @error('marca_id')
                        <div id="errorMarca" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- El proveedor dejó de ser un campo del producto: se le puede
                     comprar a varios, con un costo distinto cada uno, y eso no
                     entra en un selector. Queda el camino a la pantalla que lo
                     administra, para que quitar el campo no parezca que la
                     funcionalidad desapareció. --}}
                <div class="col-12">
                    <div class="form-text mb-0">
                        @if ($esEdicion)
                            Administrá costos y proveedores asociados desde <a
                                href="{{ route('producto-proveedores.index', $producto) }}">sus proveedores</a>.
                        @else
                            Podés asociarle proveedores luego de crearlo.
                        @endif
                    </div>
                </div>
            </div>
        </fieldset>

        {{-- Precios --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3 d-flex align-items-center gap-2">
                <span>Precios</span>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="El precio sale del costo neto por la ganancia y los factores, más los impuestos. El de lista es el de contado con el recargo de cuotas adentro, y no se carga a mano."
                    aria-label="Información sobre los precios">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </legend>

            {{-- Advertencia operativa: si no se lee, el alta falla. --}}
            @unless ($esEdicion)
                <div class="alert alert-light border d-flex align-items-start gap-2 small py-2" role="note">
                    <i class="bi bi-exclamation-triangle text-warning flex-shrink-0 mt-1" aria-hidden="true"></i>
                    <span>
                        Un producto nuevo todavía no tiene costo, así que su precio se carga a mano.
                        Cuando reciba mercadería vas a poder pasarlo al cálculo automático.
                    </span>
                </div>
            @endunless

            <div class="row g-3">
                {{-- Gobierna a los demás campos, así que va primero. --}}
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input @error('precio_manual') is-invalid @enderror" type="checkbox"
                            value="1" id="precio_manual" name="precio_manual" @checked(old('precio_manual', $producto->precio_manual))
                            @disabled(!$esEdicion)
                            aria-describedby="ayudaManual @error('precio_manual') errorManual @enderror">

                        <label class="form-check-label" for="precio_manual">Fijar el precio a mano</label>

                        {{-- Un control deshabilitado no se envía, y el servidor exige
                             la marca en el alta: el valor viaja igual, por acá. --}}
                        @unless ($esEdicion)
                            <input type="hidden" name="precio_manual" value="1">
                        @endunless

                        <div id="ayudaManual" class="form-text">
                            Sin la marca, el precio lo calcula el motor y el ajuste masivo lo mueve.
                        </div>

                        @error('precio_manual')
                            <div id="errorManual" class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="precio_contado" class="form-label">
                        Precio de contado <span aria-hidden="true">*</span>
                    </label>

                    {{-- Texto y no type="number": un campo numérico del navegador
                         reinterpreta "1.500,50" según el idioma del sistema. --}}
                    <div class="input-group has-validation">
                        <span class="input-group-text" aria-hidden="true">$</span>
                        <input type="text" inputmode="decimal" autocomplete="off"
                            class="form-control @error('precio_contado') is-invalid @enderror" id="precio_contado"
                            name="precio_contado"
                            value="{{ Importe::paraFormulario(old('precio_contado', $producto->precio_contado)) }}"
                            @disabled($esEdicion && !old('precio_manual', $producto->precio_manual))
                            aria-describedby="ayudaContado @error('precio_contado') errorContado @enderror">

                        @error('precio_contado')
                            <div id="errorContado" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div id="ayudaContado" class="form-text">Efectivo, transferencia o QR.</div>
                </div>

                <div class="col-12 col-md-4">
                    <span class="form-label d-block">Precio de lista</span>

                    <p class="form-control-plaintext fw-semibold mb-0">
                        {{ $esEdicion ? Importe::pesos($producto->precio_lista) : '—' }}
                    </p>

                    <div class="form-text">
                        Tarjeta y cuotas. Sale del recargo que fija
                        @can('precio.ver')
                            <a href="{{ route('precios.index') }}">Precios</a>.
                        @else
                            la pantalla de Precios.
                        @endcan
                    </div>
                </div>

                <div class="col-6 col-md-2">
                    <label for="ganancia" class="form-label">Ganancia</label>

                    <input type="text" inputmode="decimal" autocomplete="off"
                        class="form-control @error('ganancia') is-invalid @enderror" id="ganancia" name="ganancia"
                        value="{{ old('ganancia', Importe::numero($producto->ganancia)) }}"
                        placeholder="{{ $calculo ? Importe::numero($calculo['ganancia']) : '' }}"
                        aria-describedby="ayudaGananciaProd @error('ganancia') errorGananciaProd @enderror">

                    <div id="ayudaGananciaProd" class="form-text">Vacía: hereda de la categoría.</div>

                    @error('ganancia')
                        <div id="errorGananciaProd" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-6 col-md-2">
                    <label for="alicuota_iva" class="form-label">IVA <span aria-hidden="true">*</span></label>

                    {{-- Las opciones salen del catálogo de impuestos, que es con lo
                         que valida el Request: comparan el mismo texto. --}}
                    <select class="form-select @error('alicuota_iva') is-invalid @enderror" id="alicuota_iva"
                        name="alicuota_iva" required aria-describedby="@error('alicuota_iva') errorIva @enderror">
                        @foreach ($alicuotas as $valor => $texto)
                            <option value="{{ $valor }}" @selected((string) old('alicuota_iva', $producto->alicuota_iva) === (string) $valor)>
                                {{ $texto }}
                            </option>
                        @endforeach
                    </select>

                    @error('alicuota_iva')
                        <div id="errorIva" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </fieldset>

        {{-- Stock y reposición --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <div class="d-flex align-items-center gap-2 mb-3">
                <legend class="h5 mb-0">Stock y reposición</legend>
                <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                    data-bs-toggle="tooltip" data-bs-placement="top"
                    data-bs-title="{{ $esEdicion ? 'El stock y costo se actualizan mediante compras o ajustes.' : 'El producto se crea sin stock inicial; ingresa mediante compras o ajustes.' }}"
                    aria-label="Información sobre stock y costo">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            @if ($esEdicion)
                {{-- De sólo lectura: el stock y el costo se mueven con compras y
                     ajustes, siempre dejando registro (A-13). --}}
                <dl class="row mb-3">
                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Stock disponible</dt>
                    <dd class="col-6 col-md-3 fw-semibold">{{ $producto->stock_disponible }} u.</dd>

                    @can('precio.ver_costo')
                        <dt class="col-6 col-md-3 fw-normal text-body-secondary">Costo promedio</dt>
                        <dd class="col-6 col-md-3 fw-semibold">
                            {{ Importe::pesos($producto->costo_promedio) }}
                            <span class="text-body-secondary fw-normal small">neto, sin IVA</span>
                        </dd>
                    @endcan
                </dl>

                <p class="form-text mt-n2 mb-3">
                    El stock y el costo se actualizan al recibir compras o al registrar ajustes de stock.
                </p>
            @endif

            <div class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label for="stock_minimo" class="form-label">Stock mínimo</label>
                    <input type="number" inputmode="numeric" min="0" max="100000"
                        class="form-control @error('stock_minimo') is-invalid @enderror" id="stock_minimo"
                        name="stock_minimo" value="{{ old('stock_minimo', $producto->stock_minimo) }}"
                        aria-describedby="ayudaMinimo @error('stock_minimo') errorMinimo @enderror">
                    <div id="ayudaMinimo" class="form-text">Con esta cantidad o menos, hay que reponer.</div>
                    @error('stock_minimo')
                        <div id="errorMinimo" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12 col-sm-6 col-md-3">
                    <label for="cantidad_reposicion" class="form-label">Cantidad a reponer</label>
                    <input type="number" inputmode="numeric" min="0" max="100000"
                        class="form-control @error('cantidad_reposicion') is-invalid @enderror" id="cantidad_reposicion"
                        name="cantidad_reposicion"
                        value="{{ old('cantidad_reposicion', $producto->cantidad_reposicion) }}"
                        aria-describedby="ayudaReposicion @error('cantidad_reposicion') errorReposicion @enderror">
                    <div id="ayudaReposicion" class="form-text">Cuántas unidades pedir cada vez.</div>
                    @error('cantidad_reposicion')
                        <div id="errorReposicion" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </fieldset>

        {{-- Imágenes --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Imágenes</legend>

            <x-gestor-imagenes :actuales="$imagenes" :max="Producto::MAX_IMAGENES" :descripcion="$producto->nombre ?: 'el producto'" />
        </fieldset>

        {{-- Estado --}}
        <div class="card card-body border-0 shadow-sm mb-4">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="activo" name="activo"
                    @checked(old('activo', $producto->activo ?? true))>
                <label class="form-check-label" for="activo">Producto activo</label>
                <div class="form-text">Un producto inactivo no se ofrece en ventas.</div>
            </div>
        </div>

        <div class="d-grid d-sm-flex justify-content-sm-end gap-2 mt-4 pt-3 border-top">
            <a href="{{ route('productos.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-acento px-4">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear producto' }}
            </button>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Cómo sale su precio                                              --}}
    {{-- ---------------------------------------------------------------- --}}

    @if ($esEdicion)
        <section class="card border-0 shadow-sm mb-4">
            <div
                class="card-header bg-white border-0 pt-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Cómo sale su precio</h2>
                    <button type="button" class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                        data-bs-toggle="tooltip" data-bs-placement="top"
                        data-bs-title="El precio que tendría hoy según el costo, la ganancia heredada y los factores vigentes. Recalcular lo escribe en el producto y deja su fila en el historial."
                        aria-label="Información sobre el cálculo del precio">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                    </button>
                </div>

                @can('precio.recalcular')
                    <form method="POST" action="{{ route('productos.recalcular', $producto) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-dark text-nowrap"
                            aria-label="Recalcular el precio de {{ $producto->nombre }}">
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recalcular
                        </button>
                    </form>
                @endcan
            </div>

            <div class="card-body pt-2">
                @if ($producto->precio_manual)
                    <div class="alert alert-light border d-flex align-items-start gap-2 small py-2" role="note">
                        <i class="bi bi-pin-angle text-body-secondary flex-shrink-0 mt-1" aria-hidden="true"></i>
                        <span>
                            Este producto tiene el precio fijado a mano: ni «Recalcular» ni el ajuste
                            masivo lo cambian. Lo de abajo es lo que saldría si lo dejaras calcular.
                        </span>
                    </div>
                @endif

                @can('precio.ver_costo')
                    @if ((float) $producto->costo_promedio <= 0)
                        <p class="text-body-secondary mb-0">
                            Todavía no tiene costo cargado, así que no hay precio que calcular. El costo
                            entra al recibir una compra.
                        </p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <caption class="visually-hidden">Del costo neto al precio de contado</caption>
                                <tbody>
                                    <tr>
                                        <th scope="row" class="fw-normal">Costo neto</th>
                                        <td class="text-end">{{ Importe::pesos($calculo['costo_neto']) }}</td>
                                    </tr>

                                    <tr>
                                        <th scope="row" class="fw-normal">
                                            Ganancia
                                            <span class="text-body-secondary">
                                                × {{ Importe::numero($calculo['ganancia']) }}
                                                @if ($producto->ganancia === null)
                                                    (heredada)
                                                @endif
                                            </span>
                                        </th>
                                        <td class="text-end text-body-secondary">
                                            {{ Importe::pesos(round($calculo['costo_neto'] * $calculo['ganancia'], 2)) }}
                                        </td>
                                    </tr>

                                    @foreach ($calculo['factores'] as $factor)
                                        <tr>
                                            <th scope="row" class="fw-normal">
                                                {{ $factor['nombre'] }}
                                                <span class="text-body-secondary">
                                                    {{ $factor['modo'] === 'recupero' ? '÷' : '×' }}
                                                    {{ Importe::numero(
                                                        $factor['modo'] === 'recupero' ? 1 - $factor['porcentaje'] / 100 : 1 + $factor['porcentaje'] / 100,
                                                        4,
                                                    ) }}
                                                </span>
                                            </th>
                                            <td class="text-end text-body-secondary">
                                                + {{ Importe::pesos($factor['importe']) }}
                                            </td>
                                        </tr>
                                    @endforeach

                                    <tr class="border-top">
                                        <th scope="row" class="fw-normal">Neto de venta</th>
                                        <td class="text-end">{{ Importe::pesos($calculo['contado']['neto']) }}</td>
                                    </tr>

                                    @foreach ($calculo['contado']['impuestos'] as $impuesto)
                                        <tr>
                                            <th scope="row" class="fw-normal">
                                                {{ $impuesto['nombre'] }}
                                                <span class="text-body-secondary">
                                                    {{ Importe::numero($impuesto['alicuota'], 2) }} %
                                                </span>
                                            </th>
                                            <td class="text-end">{{ Importe::pesos($impuesto['importe']) }}</td>
                                        </tr>
                                    @endforeach

                                    <tr class="border-top">
                                        <th scope="row" class="h6 mb-0">Precio calculado</th>
                                        <td class="text-end h6 mb-0">{{ Importe::pesos($calculo['contado']['final']) }}</td>
                                    </tr>

                                    {{-- La comparación es lo que hace útil al panel: dice si el
                                         producto está al día o quedó atrás. --}}
                                    @if ((float) $producto->precio_contado !== $calculo['contado']['final'])
                                        <tr>
                                            <th scope="row" class="fw-normal text-body-secondary">Hoy está en</th>
                                            <td class="text-end text-body-secondary">
                                                {{ Importe::pesos($producto->precio_contado) }}
                                            </td>
                                        </tr>
                                    @endif

                                    <tr>
                                        <th scope="row" class="fw-normal">Margen actual sobre la venta</th>
                                        <td class="text-end">
                                            {{ $producto->margen() === null ? '—' : Importe::numero($producto->margen(), 2) . ' %' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endcan
            </div>

            {{-- El historial no muestra costos, así que no pide `precio.ver_costo`. --}}
            @if ($historial->isNotEmpty())
                <div class="card-header bg-white border-0 border-top pt-3">
                    <h3 class="h6 mb-0">Últimos cambios de precio</h3>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">Historial de precios del producto</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Cuándo</th>
                                <th scope="col" class="text-end">De</th>
                                <th scope="col" class="text-end">A</th>
                                <th scope="col" class="d-none d-md-table-cell">Motivo</th>
                                <th scope="col" class="d-none d-md-table-cell">Quién</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($historial as $cambio)
                                <tr>
                                    <td class="small">{{ $cambio->created_at?->format('d/m/Y H:i') }}</td>
                                    <td class="text-end">{{ Importe::pesos($cambio->precio_contado_anterior) }}</td>
                                    <td class="text-end">{{ Importe::pesos($cambio->precio_contado_nuevo) }}</td>
                                    <td class="d-none d-md-table-cell small text-body-secondary">
                                        {{ \App\Models\HistorialPrecio::MOTIVOS[$cambio->motivo] }}
                                    </td>
                                    <td class="d-none d-md-table-cell small text-body-secondary">
                                        {{ $cambio->usuario?->nombre ?? 'El sistema' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
