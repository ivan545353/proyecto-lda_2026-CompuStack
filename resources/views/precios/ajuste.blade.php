@extends('layouts.app')

@use('App\Models\LoteAjuste')
@use('App\Services\PrecioService')
@use('App\Support\Importe')

@section('title', 'Ajuste masivo de precios')

@section('content')
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1">Ajuste masivo de precios</h1>
            <p class="text-body-secondary small mb-0">
                Actualizá precios, costos o márgenes sobre múltiples productos a la vez de forma segura.
            </p>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2">
            <a href="{{ route('precios.lotes') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Historial
            </a>

            <a href="{{ route('precios.index') }}" class="btn btn-outline-dark text-nowrap">
                <i class="bi bi-sliders" aria-hidden="true"></i> Volver a Precios
            </a>
        </div>
    </div>

    @include('partials.errores')

    {{-- Banner de seguridad: aviso claro y tranquilizador --}}
    <div class="alert alert-light border shadow-sm mb-4" role="note">
        <div class="d-flex align-items-center gap-3">
            <div class="p-2 bg-primary-subtle text-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                style="width: 40px; height: 40px;">
                <i class="bi bi-shield-check fs-5" aria-hidden="true"></i>
            </div>
            <div class="small flex-grow-1">
                <strong>Modo seguro:</strong> Ningún precio se modificará hasta que revises la previsualización y confirmes
                la operación.
                Además, todos los ajustes quedan registrados en el historial y pueden revertirse en cualquier momento.
            </div>
        </div>
    </div>

    {{-- Formulario de previsualización (vía GET) --}}
    <form method="GET" action="{{ route('precios.ajuste') }}" id="formAjuste" novalidate>
        <input type="hidden" name="previsualizar" value="1">
        @php($operacionActual = old('operacion', $datos['operacion'] ?? 'precio'))
        {{-- PASO 1: OPERACIÓN Y PARÁMETROS --}}
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                <span class="badge bg-dark rounded-pill">Paso 1</span>
                <h2 class="h5 mb-0">¿Qué querés modificar?</h2>
                <button type="button"
                    class="btn btn-link p-0 text-body-secondary border-0 d-inline-flex align-items-center"
                    data-bs-toggle="tooltip" data-bs-container="body" data-bs-placement="top"
                    data-bs-title="Elegí la operación y el valor a aplicar. Solo debés completar el parámetro correspondiente a la operación elegida."
                    aria-label="Información sobre la operación del ajuste">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            <div class="card-body pt-2">
                <div class="row g-3">
                    {{-- Selector de operación --}}
                    <div class="col-12 col-md-5">
                        <label for="operacion" class="form-label fw-semibold">
                            Operación a realizar <span class="text-danger" aria-hidden="true">*</span>
                        </label>

                        <select class="form-select @error('operacion') is-invalid @enderror" id="operacion" name="operacion"
                            required aria-describedby="explicacionOperacion @error('operacion') errorOperacion @enderror">
                            @foreach (LoteAjuste::ELEGIBLES as $clave)
                                <option value="{{ $clave }}" @selected($operacionActual === $clave)>
                                    {{ LoteAjuste::OPERACIONES[$clave] }}
                                </option>
                            @endforeach
                        </select>

                        <div id="explicacionOperacion" class="form-text mt-2 text-muted"></div>

                        @error('operacion')
                            <div id="errorOperacion" class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Parámetros dinámicos según operación --}}
                    <div class="col-12 col-md-7">
                        {{-- Campo Porcentaje (para Variar Precio o Variar Costo) --}}
                        <div id="campoPorcentaje" @class([
                            'd-none' => !in_array($operacionActual, ['precio', 'costo'], true),
                        ])>
                            <label for="porcentaje" class="form-label fw-semibold">
                                Porcentaje de variación <span class="text-danger" aria-hidden="true">*</span>
                            </label>

                            <div class="input-group has-validation">
                                <input type="text" inputmode="decimal"
                                    class="form-control @error('porcentaje') is-invalid @enderror" id="porcentaje"
                                    name="porcentaje" value="{{ old('porcentaje', $datos['porcentaje'] ?? '') }}"
                                    placeholder="Ej: 15 o -5"
                                    aria-describedby="ayudaPorcentaje @error('porcentaje') errorPorcentaje @enderror">
                                <span class="input-group-text fw-semibold">%</span>

                                @error('porcentaje')
                                    <div id="errorPorcentaje" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div id="ayudaPorcentaje" class="form-text">
                                Ingresá valores positivos para aumento (ej: <strong>15</strong>) o negativos para rebaja
                                (ej: <strong>-5</strong>).
                            </div>
                        </div>

                        {{-- Campo Multiplicador de Ganancia --}}
                        <div id="campoGanancia" @class(['d-none' => $operacionActual !== 'ganancia'])>
                            <label for="ganancia" class="form-label fw-semibold">
                                Multiplicador de ganancia <span class="text-danger" aria-hidden="true">*</span>
                            </label>

                            <input type="text" inputmode="decimal"
                                class="form-control @error('ganancia') is-invalid @enderror" id="ganancia" name="ganancia"
                                value="{{ old('ganancia', $datos['ganancia'] ?? '') }}" placeholder="Ej: 2.500"
                                aria-describedby="ayudaGananciaAjuste @error('ganancia') errorGananciaAjuste @enderror">

                            <div id="ayudaGananciaAjuste" class="form-text">
                                Multiplicador aplicado sobre el costo neto (ej: <strong>2,5</strong> equivale a 150 % de
                                margen).
                            </div>

                            @error('ganancia')
                                <div id="errorGananciaAjuste" class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        {{-- Campo Múltiplo de Redondeo --}}
                        <div id="campoMultiplo" @class(['d-none' => $operacionActual !== 'redondeo'])>
                            <label for="redondeo_multiplo" class="form-label fw-semibold">
                                Múltiplo de redondeo <span class="text-danger" aria-hidden="true">*</span>
                            </label>

                            <div class="input-group has-validation">
                                <span class="input-group-text">$</span>
                                <input type="text" inputmode="decimal"
                                    class="form-control @error('redondeo_multiplo') is-invalid @enderror"
                                    id="redondeo_multiplo" name="redondeo_multiplo"
                                    value="{{ old('redondeo_multiplo', $datos['redondeo_multiplo'] ?? '') }}"
                                    placeholder="Ej: 100 o 1000"
                                    aria-describedby="ayudaMultiploAjuste @error('redondeo_multiplo') errorMultiploAjuste @enderror">

                                @error('redondeo_multiplo')
                                    <div id="errorMultiploAjuste" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div id="ayudaMultiploAjuste" class="form-text">
                                Redondea los precios de venta al múltiplo más cercano (ej: <strong>100</strong> lleva $1.234
                                a $1.200).
                            </div>
                        </div>

                        {{-- Aviso para Recalcular --}}
                        <div id="avisoRecalculo" @class([
                            'alert alert-light border mb-0 small',
                            'd-none' => $operacionActual !== 'recalculo',
                        ])>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-arrow-repeat text-primary fs-5"></i>
                                <div>
                                    <strong>Recálculo automático:</strong> Los precios de venta se calcularán a partir de
                                    sus costos
                                    vigentes y los factores configurados (impuestos y márgenes). No requiere parámetros
                                    numéricos adicionales.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- PASO 2: SELECCIÓN DE PRODUCTOS --}}
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                <span class="badge bg-dark rounded-pill">Paso 2</span>
                <h2 class="h5 mb-0">¿A qué productos aplicar?</h2>
                <button type="button"
                    class="btn btn-link p-0 text-body-secondary border-0 d-inline-flex align-items-center"
                    data-bs-toggle="tooltip" data-bs-container="body" data-bs-placement="top"
                    data-bs-title="Podés aplicar el ajuste a todo el catálogo o combinar filtros (categoría, marca, proveedor o rangos de precios). La categoría incluye sus subcategorías."
                    aria-label="Información sobre el alcance del ajuste">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            <div class="card-body pt-2">
                @php($alcance = old('alcance', $datos['alcance'] ?? 'recorte'))

                {{-- Opciones de Alcance en tarjetas de radio claras --}}
                <fieldset class="mb-4">
                    <legend class="form-label fw-semibold mb-2">
                        Alcance del ajuste <span class="text-danger" aria-hidden="true">*</span>
                    </legend>

                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <label
                                class="form-check p-3 border rounded-3 h-100 cursor-pointer d-flex align-items-start gap-2"
                                for="alcanceRecorte" id="tarjetaAlcanceRecorte">
                                <input class="form-check-input mt-1 @error('alcance') is-invalid @enderror" type="radio"
                                    name="alcance" id="alcanceRecorte" value="recorte" @checked($alcance === 'recorte')
                                    aria-describedby="@error('alcance') errorAlcance @enderror">
                                <div>
                                    <div class="fw-semibold">Filtrar productos específicos</div>
                                    <div class="text-body-secondary small">
                                        Aplica el cambio sólo a los productos que cumplan con los filtros de abajo
                                        (categoría, marca, proveedor, etc.).
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="col-12 col-md-6">
                            <label
                                class="form-check p-3 border rounded-3 h-100 cursor-pointer d-flex align-items-start gap-2"
                                for="alcanceTodos" id="tarjetaAlcanceTodos">
                                <input class="form-check-input mt-1 @error('alcance') is-invalid @enderror" type="radio"
                                    name="alcance" id="alcanceTodos" value="todos" @checked($alcance === 'todos')
                                    aria-describedby="@error('alcance') errorAlcance @enderror">
                                <div>
                                    <div class="fw-semibold">Todo el catálogo completo</div>
                                    <div class="text-body-secondary small">
                                        Aplica el cambio a todos los productos del catálogo, incluidos los
                                        dados de baja.
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    @error('alcance')
                        <div id="errorAlcance" class="invalid-feedback d-block mt-2">{{ $message }}</div>
                    @enderror
                </fieldset>

                {{-- Aviso cuando se selecciona "Todo el catálogo" --}}
                <div id="avisoCatalogoCompleto" @class([
                    'alert alert-info py-3 px-3 small d-flex align-items-center gap-2 mb-3',
                    'd-none' => $alcance !== 'todos',
                ])>
                    <i class="bi bi-info-circle-fill text-info fs-5 flex-shrink-0"></i>
                    <div>
                        Se aplicará el ajuste sobre <strong>todos los productos del catálogo</strong>,
                        incluidos los dados de baja: un discontinuado que sigue en el estante se sigue
                        vendiendo.
                    </div>
                </div>

                {{-- Bloque de Filtros (Se oculta y deshabilita si alcance === 'todos') --}}
                <div id="bloqueFiltrosRecorte" @class(['d-none' => $alcance === 'todos'])>
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <h3 class="h6 fw-bold mb-3 text-body-secondary text-uppercase small"
                            style="letter-spacing: 0.5px;">
                            Filtros de catálogo
                        </h3>

                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label for="categoria_id" class="form-label">Categoría</label>
                                <select class="form-select @error('categoria_id') is-invalid @enderror" id="categoria_id"
                                    name="categoria_id" data-buscable="jerarquia"
                                    aria-describedby="ayudaCategoriaAjuste @error('categoria_id') errorCategoriaAjuste @enderror">
                                    <option value="">Todas las categorías</option>
                                    <x-opciones-categoria :categorias="$categorias" :seleccionada="old('categoria_id', $datos['categoria_id'] ?? null)" />
                                </select>

                                <div id="ayudaCategoriaAjuste" class="form-text">
                                    Incluye automáticamente sus subcategorías.
                                </div>

                                @error('categoria_id')
                                    <div id="errorCategoriaAjuste" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="marca_id" class="form-label">Marca</label>
                                <select class="form-select @error('marca_id') is-invalid @enderror" id="marca_id"
                                    name="marca_id" data-buscable
                                    aria-describedby="@error('marca_id') errorMarcaAjuste @enderror">
                                    <option value="">Todas las marcas</option>
                                    @foreach ($marcas as $marca)
                                        <option value="{{ $marca->id }}" @selected((string) old('marca_id', $datos['marca_id'] ?? '') === (string) $marca->id)>
                                            {{ $marca->nombre }}{{ $marca->activo ? '' : ' (inactiva)' }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('marca_id')
                                    <div id="errorMarcaAjuste" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-4">
                                <label for="proveedor_id" class="form-label">Proveedor</label>
                                <select class="form-select @error('proveedor_id') is-invalid @enderror" id="proveedor_id"
                                    name="proveedor_id" data-buscable
                                    aria-describedby="ayudaProveedorAjuste @error('proveedor_id') errorProveedorAjuste @enderror">
                                    <option value="">Todos los proveedores</option>
                                    @foreach ($proveedores as $proveedor)
                                        <option value="{{ $proveedor->id }}" @selected((string) old('proveedor_id', $datos['proveedor_id'] ?? '') === (string) $proveedor->id)>
                                            {{ $proveedor->razon_social }}{{ $proveedor->activo ? '' : ' (inactivo)' }}
                                        </option>
                                    @endforeach
                                </select>

                                <div id="ayudaProveedorAjuste" class="form-text">
                                    Ideal cuando un mayorista actualiza su lista.
                                </div>

                                @error('proveedor_id')
                                    <div id="errorProveedorAjuste" class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        {{-- Rangos de Costo y Precio agrupados limpiamente --}}
                        <div class="row g-3 mt-1">
                            @foreach ([
            'costo' => 'Rango de costo de compra',
            'precio' => 'Rango de precio de venta (contado)',
        ] as $grupo => $rotulo)
                                <div class="col-12 col-md-6">
                                    <span class="form-label fw-semibold small text-body-secondary d-block">
                                        {{ $rotulo }}
                                    </span>

                                    <div class="row g-2">
                                        @foreach (['desde' => 'mín.', 'hasta' => 'máx.'] as $extremo => $corto)
                                            @php($nombre = "{$grupo}_{$extremo}")

                                            <div class="col-6">
                                                <div class="input-group input-group-sm has-validation">
                                                    <span class="input-group-text">$</span>
                                                    <input type="text" inputmode="decimal"
                                                        class="form-control @error($nombre) is-invalid @enderror"
                                                        id="{{ $nombre }}" name="{{ $nombre }}"
                                                        value="{{ old($nombre, $datos[$nombre] ?? '') }}"
                                                        placeholder="{{ ucfirst($grupo) }} {{ $corto }}"
                                                        aria-label="{{ $rotulo }}, {{ $extremo }}"
                                                        aria-describedby="@error($nombre) error-{{ $nombre }} @enderror">

                                                    @error($nombre)
                                                        <div id="error-{{ $nombre }}" class="invalid-feedback">
                                                            {{ $message }}
                                                        </div>
                                                    @enderror
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Selección manual desde el catálogo --}}
                        @if (filled($datos['ids'] ?? null))
                            <div class="alert alert-secondary mt-3 mb-0 small d-flex align-items-center gap-2">
                                <i class="bi bi-check2-square flex-shrink-0" aria-hidden="true"></i>
                                <span>
                                    <strong>{{ count($datos['ids']) }}</strong> producto(s) seleccionados manualmente desde
                                    el catálogo.
                                    <a href="{{ route('precios.ajuste') }}" class="text-decoration-none ms-1">Limpiar
                                        selección</a>.
                                </span>
                            </div>

                            @foreach ($datos['ids'] as $id)
                                <input type="hidden" name="ids[]" value="{{ $id }}">
                            @endforeach
                        @endif
                    </div>
                </div>

                {{-- Salvaguarda: Casilla de Precios Manuales --}}
                <div class="form-check pt-2">
                    <input class="form-check-input" type="checkbox" value="1" id="incluir_manuales"
                        name="incluir_manuales" @checked(old('incluir_manuales', $datos['incluir_manuales'] ?? false)) aria-describedby="ayudaManuales">
                    <label class="form-check-label fw-semibold" for="incluir_manuales">
                        Incluir productos con precio fijado a mano
                    </label>
                    <div id="ayudaManuales" class="form-text">
                        Por seguridad, los productos con precio manual están protegidos y quedan fuera del ajuste a menos
                        que actives esta casilla.
                    </div>
                </div>

                <div class="d-grid d-sm-flex justify-content-sm-end mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-acento px-4 py-2">
                        <i class="bi bi-eye" aria-hidden="true"></i> Previsualizar cambios
                    </button>
                </div>
            </div>
        </section>
    </form>

    {{-- PASO 3: PREVISUALIZACIÓN Y CONFIRMACIÓN --}}
    @if ($conteos !== null)
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 pt-3 d-flex align-items-center gap-2">
                <span class="badge bg-dark rounded-pill">Paso 3</span>
                <h2 class="h5 mb-0">Previsualización del impacto</h2>
                <button type="button"
                    class="btn btn-link p-0 text-body-secondary border-0 d-inline-flex align-items-center"
                    data-bs-toggle="tooltip" data-bs-container="body" data-bs-placement="top"
                    data-bs-title="Los conteos corresponden al total de productos del catálogo que cumplen con tu selección. Todavía no se guardó ningún cambio."
                    aria-label="Información sobre la previsualización">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                </button>
            </div>

            <div class="card-body pt-2">
                {{-- Tarjetas métricas visuales para fácil comprensión --}}
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Total analizado</div>
                            <div class="fs-4 fw-bold text-dark">{{ $conteos['total'] }}</div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-primary-subtle text-center h-100 border-primary-subtle">
                            <div class="text-primary small fw-semibold">Precios a cambiar</div>
                            <div class="fs-4 fw-bold text-primary">{{ $conteos['alcanzados'] }}</div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Sin modificaciones</div>
                            <div class="fs-4 fw-bold text-secondary">{{ $conteos['sin_cambio'] }}</div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div
                            class="p-2 border rounded-3 text-center h-100 {{ $conteos['sin_costo'] > 0 ? 'bg-warning-subtle border-warning-subtle' : 'bg-light' }}">
                            <div
                                class="{{ $conteos['sin_costo'] > 0 ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary' }} small">
                                Sin costo cargado
                            </div>
                            <div
                                class="fs-4 fw-bold {{ $conteos['sin_costo'] > 0 ? 'text-warning-emphasis' : 'text-secondary' }}">
                                {{ $conteos['sin_costo'] }}
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Protegidos (precio manual)</div>
                            <div class="fs-4 fw-bold text-secondary">{{ $conteos['manuales_afuera'] }}</div>
                        </div>
                    </div>
                </div>

                {{-- Alerta crítica: Precios por debajo del costo --}}
                @if ($conteos['bajo_costo'] > 0)
                    <div class="alert alert-warning mb-3 d-flex align-items-start gap-2" role="alert">
                        <i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 fs-5" aria-hidden="true"></i>
                        <div>
                            <strong>¡Atención! {{ $conteos['bajo_costo'] }} producto(s) quedarían por debajo del costo de
                                compra.</strong>
                            Se venderían a pérdida. Están destacados en la tabla inferior con una insignia amarilla para que
                            puedas revisarlos antes de aplicar.
                        </div>
                    </div>
                @endif

                @if ($conteos['manuales_afuera'] > 0 && !filled($datos['incluir_manuales'] ?? null))
                    <div id="avisoManualesAfuera"
                        class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-shield-lock-fill text-info flex-shrink-0"></i>
                        <div>
                            Hay <strong>{{ $conteos['manuales_afuera'] }} producto(s)</strong> con precio manual que no
                            serán modificados.
                            Si querés incluirlos en este ajuste, marcá la casilla de precio manual arriba y volvé a
                            previsualizar.
                        </div>
                    </div>
                @endif

                {{-- Tabla de resultados proyectados --}}
                <div class="table-responsive border rounded-3">
                    <table class="table table-hover align-middle mb-0">
                        <caption class="visually-hidden">
                            Precio actual y precio nuevo de cada producto del recorte
                        </caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Código</th>
                                <th scope="col">Producto</th>
                                @can('precio.ver_costo')
                                    <th scope="col" class="text-end d-none d-md-table-cell">Costo</th>
                                @endcan
                                <th scope="col" class="text-end">Precio actual</th>
                                <th scope="col" class="text-end">Precio nuevo</th>
                                <th scope="col" class="text-end d-none d-md-table-cell">
                                    Variación
                                    <button type="button"
                                        class="btn btn-link p-0 text-body-secondary border-0 align-baseline"
                                        data-bs-toggle="tooltip" data-bs-placement="top"
                                        data-bs-title="Cuánto se mueve el precio de venta respecto del actual."
                                        aria-label="Información sobre la columna Variación">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    </button>
                                </th>
                                <th scope="col">Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($filas as $fila)
                                @php($producto = $fila['producto'])
                                @php($proyeccion = $fila['proyeccion'])
                                @php($actual = (float) $producto->precio_contado)
                                @php($variacion = $actual > 0 ? (($proyeccion['contado'] - $actual) / $actual) * 100 : null)

                                <tr @class(['table-warning' => $proyeccion['bajo_costo']])>
                                    <td class="font-monospace small text-body-secondary">{{ $producto->codigo }}</td>

                                    <td>
                                        @can('producto.editar')
                                            <a href="{{ route('productos.edit', $producto) }}"
                                                class="text-decoration-none fw-semibold">
                                                {{ $producto->nombre }}
                                            </a>
                                        @else
                                            <span class="fw-semibold">{{ $producto->nombre }}</span>
                                        @endcan
                                    </td>

                                    @can('precio.ver_costo')
                                        <td class="text-end d-none d-md-table-cell text-body-secondary">
                                            {{ Importe::pesos($proyeccion['costo'] ?? $producto->costo_promedio) }}
                                            @if ($proyeccion['costo'] !== null)
                                                <span class="badge bg-dark-subtle text-dark-emphasis border ms-1">nuevo</span>
                                            @endif
                                        </td>
                                    @endcan

                                    <td class="text-end">{{ Importe::pesos($producto->precio_contado) }}</td>

                                    <td class="text-end fw-bold text-dark">{{ Importe::pesos($proyeccion['contado']) }}
                                    </td>

                                    <td class="text-end d-none d-md-table-cell">
                                        @if ($variacion === null)
                                            <span class="text-body-secondary">—</span>
                                        @elseif ($variacion > 0)
                                            <span class="text-success fw-semibold">+{{ Importe::numero($variacion, 2) }}
                                                %</span>
                                        @elseif ($variacion < 0)
                                            <span class="text-danger fw-semibold">{{ Importe::numero($variacion, 2) }}
                                                %</span>
                                        @else
                                            <span class="text-body-secondary">0 %</span>
                                        @endif
                                    </td>

                                    <td class="text-nowrap">
                                        @if ($proyeccion['bajo_costo'])
                                            <span class="badge text-bg-warning">
                                                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Debajo del
                                                costo
                                            </span>
                                        @elseif ($proyeccion['resultado'] === PrecioService::APLICADO)
                                            <span class="badge text-bg-dark">Cambia precio</span>
                                        @elseif ($proyeccion['resultado'] === PrecioService::SIN_COSTO)
                                            <span class="badge text-bg-secondary">Sin costo</span>
                                        @else
                                            <span class="badge text-bg-light border text-body-secondary">Queda igual</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-body-secondary py-4">
                                        @if (($datos['alcance'] ?? '') === 'todos' && $conteos['manuales_afuera'] === 0)
                                            Todavía no hay productos cargados en el catálogo.
                                        @else
                                            Ningún producto coincide con el filtro seleccionado. Probá cambiando los
                                            criterios o marcá la casilla para incluir productos con precio manual.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($filas->hasPages())
                    <div class="pt-3">{{ $filas->links() }}</div>
                @endif

                {{-- Confirmación y Aplicación --}}
                @if ($conteos['alcanzados'] > 0)
                    @can('precio.ajustar_masivo')
                        <form method="POST" action="{{ route('precios.ajuste.aplicar') }}" class="pt-3 border-top mt-3"
                            onsubmit="return confirm(@js("¿Confirmás la aplicación del ajuste? Se actualizarán los precios de {$conteos['alcanzados']} producto(s). Podrás deshacer este cambio desde el módulo de precios."));">
                            @csrf

                            @foreach ($datos as $campo => $valor)
                                @continue($campo === 'page')

                                @if (is_array($valor))
                                    @foreach ($valor as $elemento)
                                        <input type="hidden" name="{{ $campo }}[]" value="{{ $elemento }}">
                                    @endforeach
                                @else
                                    <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                                @endif
                            @endforeach

                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <div class="text-body-secondary small">
                                    <i class="bi bi-info-circle me-1"></i> Se aplicará el ajuste únicamente a los
                                    <strong>{{ $conteos['alcanzados'] }}</strong> productos cuyo precio se modifica.
                                </div>

                                <button type="submit" class="btn btn-acento btn-lg px-4 text-nowrap">
                                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                                    Confirmar y aplicar a {{ $conteos['alcanzados'] }} producto(s)
                                </button>
                            </div>
                        </form>
                    @endcan
                @endif
            </div>
        </section>
    @endif


@endsection
