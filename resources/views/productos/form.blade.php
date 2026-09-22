@extends('layouts.app')

@use('App\Models\Producto')
@use('App\Support\Importe')

@php($esEdicion = $producto->exists)
@php($imagenes = $producto->imagenes ?? [])
{{-- Si el contado es igual al de lista, el campo se muestra vacío: así sigue
     al precio de lista si este cambia, en vez de quedar clavado en el viejo. --}}
@php($contadoGuardado = $producto->precio_contado === $producto->precio_lista ? null : $producto->precio_contado)

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

                <div class="col-12 col-sm-6 col-md-4">
                    <label for="proveedor_id" class="form-label">Proveedor</label>
                    <select class="form-select @error('proveedor_id') is-invalid @enderror" id="proveedor_id"
                        name="proveedor_id" data-buscable
                        aria-describedby="ayudaProveedor @error('proveedor_id') errorProveedor @enderror">
                        <option value="">Sin proveedor</option>
                        @foreach ($proveedores as $opcion)
                            <option value="{{ $opcion->id }}" @selected((string) old('proveedor_id', $producto->proveedor_id) === (string) $opcion->id)>
                                {{ $opcion->razon_social }}{{ $opcion->activo ? '' : ' (inactivo)' }}
                            </option>
                        @endforeach
                    </select>
                    <div id="ayudaProveedor" class="form-text">A quién se le pide cuando hay que reponer.</div>
                    @error('proveedor_id')
                        <div id="errorProveedor" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </fieldset>

        {{-- Precios --}}
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Precios</legend>

            <div class="row g-3">
                <div class="col-12 col-md-4">
                    <label for="precio_lista" class="form-label">Precio de lista <span aria-hidden="true">*</span></label>
                    {{-- Texto y no type="number": un campo numérico del navegador
                         reinterpreta "1.500,50" según el idioma del sistema. --}}
                    <div class="input-group has-validation">
                        <span class="input-group-text" aria-hidden="true">$</span>
                        <input type="text" inputmode="decimal" autocomplete="off"
                            class="form-control @error('precio_lista') is-invalid @enderror" id="precio_lista"
                            name="precio_lista"
                            value="{{ Importe::paraFormulario(old('precio_lista', $producto->precio_lista)) }}" required
                            aria-describedby="ayudaLista @error('precio_lista') errorLista @enderror">
                        @error('precio_lista')
                            <div id="errorLista" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div id="ayudaLista" class="form-text">Con tarjeta o en cuotas. Por ejemplo: 1.500,50</div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="precio_contado" class="form-label">Precio de contado</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text" aria-hidden="true">$</span>
                        <input type="text" inputmode="decimal" autocomplete="off"
                            class="form-control @error('precio_contado') is-invalid @enderror" id="precio_contado"
                            name="precio_contado"
                            value="{{ Importe::paraFormulario(old('precio_contado', $contadoGuardado)) }}"
                            aria-describedby="ayudaContado @error('precio_contado') errorContado @enderror">
                        @error('precio_contado')
                            <div id="errorContado" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div id="ayudaContado" class="form-text">
                        Efectivo, transferencia o QR. Si lo dejás vacío, se usa el precio de lista.
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <label for="alicuota_iva" class="form-label">IVA <span aria-hidden="true">*</span></label>
                    <select class="form-select @error('alicuota_iva') is-invalid @enderror" id="alicuota_iva"
                        name="alicuota_iva" required aria-describedby="@error('alicuota_iva') errorIva @enderror">
                        {{-- Las opciones salen de la misma constante con la que valida
                             el Request: comparan exactamente el mismo texto. --}}
                        @foreach (Producto::ALICUOTAS_IVA as $valor => $texto)
                            <option value="{{ $valor }}" @selected((string) old('alicuota_iva', $producto->alicuota_iva) === $valor)>{{ $texto }}</option>
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
            <legend class="h5 mb-3">Stock y reposición</legend>

            @if ($esEdicion)
                {{-- De sólo lectura: el stock y el costo se mueven con compras y
                     ajustes, siempre dejando registro (A-13). --}}
                <dl class="row mb-3">
                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Stock disponible</dt>
                    <dd class="col-6 col-md-3 fw-semibold">{{ $producto->stock_disponible }} u.</dd>
                    <dt class="col-6 col-md-3 fw-normal text-body-secondary">Costo promedio</dt>
                    <dd class="col-6 col-md-3 fw-semibold">{{ Importe::pesos($producto->costo_promedio) }}</dd>
                </dl>
                <p class="form-text mt-n2 mb-3">
                    El stock y el costo se actualizan al recibir compras o al registrar ajustes de stock.
                </p>
            @else
                <p class="form-text mt-0 mb-3">
                    El producto se crea sin stock. El stock entra al recibir una compra o al registrar un ajuste.
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
        @php($erroresImagenes = collect($errors->get('imagenes'))->merge(collect($errors->get('imagenes.*'))->flatten()))
        <fieldset class="card card-body border-0 shadow-sm mb-4">
            <legend class="h5 mb-3">Imágenes</legend>

            @if ($imagenes)
                <ul class="list-unstyled d-flex flex-wrap gap-3 mb-3">
                    @foreach ($imagenes as $i => $ruta)
                        <li class="text-center">
                            <img src="{{ Storage::url($ruta) }}"
                                alt="Imagen {{ $i + 1 }} de {{ $producto->nombre }}" width="96"
                                height="96" class="rounded border object-fit-cover d-block mb-1">
                            @if ($i === 0)
                                <span class="badge text-bg-dark mb-1">Principal</span>
                            @endif
                            <div class="form-check d-flex justify-content-center gap-1">
                                <input class="form-check-input" type="checkbox" name="quitar_imagenes[]"
                                    value="{{ $ruta }}" id="quitar{{ $i }}"
                                    @checked(in_array($ruta, old('quitar_imagenes', []), true))>
                                <label class="form-check-label small" for="quitar{{ $i }}">
                                    Quitar<span class="visually-hidden"> la imagen {{ $i + 1 }}</span>
                                </label>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <label for="imagenes" class="form-label">{{ $imagenes ? 'Agregar imágenes' : 'Imágenes' }}</label>
            <input type="file" multiple accept="image/jpeg,image/png,image/webp"
                class="form-control {{ $erroresImagenes->isNotEmpty() ? 'is-invalid' : '' }}" id="imagenes"
                name="imagenes[]"
                aria-describedby="ayudaImagenes {{ $erroresImagenes->isNotEmpty() ? 'errorImagenes' : '' }}">

            @if ($erroresImagenes->isNotEmpty())
                <div id="errorImagenes" class="invalid-feedback">
                    @foreach ($erroresImagenes as $mensaje)
                        <div>{{ $mensaje }}</div>
                    @endforeach
                </div>
            @endif

            <div id="ayudaImagenes" class="form-text">
                Hasta {{ Producto::MAX_IMAGENES }} en total, JPG, PNG o WEBP de hasta 1 MB cada una. La primera es la
                principal.
                @if ($imagenes)
                    Tiene {{ count($imagenes) }}: podés agregar {{ Producto::MAX_IMAGENES - count($imagenes) }} más,
                    o quitar alguna para hacer lugar.
                @endif
            </div>

            {{-- El navegador no permite volver a completar un campo de archivo:
                 si hubo un error en cualquier campo, las imágenes elegidas se
                 perdieron. Mejor decirlo que dejar que el usuario lo descubra. --}}
            @if ($errors->any())
                <p class="form-text text-danger-emphasis mb-0 mt-2">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Si habías elegido imágenes, volvé a seleccionarlas: por seguridad, el navegador no las conserva.
                </p>
            @endif
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

        <div class="d-grid d-sm-flex gap-2">
            <button type="submit" class="btn btn-acento">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $esEdicion ? 'Guardar cambios' : 'Crear producto' }}
            </button>
            <a href="{{ route('productos.index') }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </form>
@endsection
