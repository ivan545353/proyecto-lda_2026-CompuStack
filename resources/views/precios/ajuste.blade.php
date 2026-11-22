@extends('layouts.app')

@use('App\Models\LoteAjuste')

{{-- Shell (regla 37): las opciones del recorte, los conteos y la tabla los trae pantallas/ajuste-masivo.js.
     Los valores del formulario salen de la URL, que es lo que el operador eligió, no datos de negocio. --}}
@php($operacionActual = request('operacion', 'precio'))
@php($alcance = request('alcance', 'recorte'))
@php($ids = array_values(array_filter((array) request('ids', []), 'is_numeric')))

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

    <div data-pantalla="ajuste-masivo" data-lotes="{{ route('precios.lotes') }}">
        {{-- Formulario de previsualización (vía GET): la vista previa queda en la URL. El JS la lee y se la pide a la API. --}}
        <form method="GET" action="{{ route('precios.ajuste') }}" id="formAjuste" novalidate data-formulario-ajuste>
            <input type="hidden" name="previsualizar" value="1">
            <p class="alert alert-danger" data-errores-formulario hidden></p>

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

                            <select class="form-select" id="operacion" name="operacion" required
                                aria-describedby="explicacionOperacion">
                                @foreach (LoteAjuste::ELEGIBLES as $clave)
                                    <option value="{{ $clave }}" @selected($operacionActual === $clave)>
                                        {{ LoteAjuste::OPERACIONES[$clave] }}
                                    </option>
                                @endforeach
                            </select>

                            <div id="explicacionOperacion" class="form-text mt-2 text-muted"></div>
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
                                    <input type="text" inputmode="decimal" class="form-control" id="porcentaje"
                                        name="porcentaje" value="{{ request('porcentaje') }}" placeholder="Ej: 15 o -5"
                                        aria-describedby="ayudaPorcentaje">
                                    <span class="input-group-text fw-semibold">%</span>
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

                                <input type="text" inputmode="decimal" class="form-control" id="ganancia" name="ganancia"
                                    value="{{ request('ganancia') }}" placeholder="Ej: 2.500"
                                    aria-describedby="ayudaGananciaAjuste">

                                <div id="ayudaGananciaAjuste" class="form-text">
                                    Multiplicador aplicado sobre el costo neto (ej: <strong>2,5</strong> equivale a 150 % de
                                    margen).
                                </div>
                            </div>

                            {{-- Campo Múltiplo de Redondeo --}}
                            <div id="campoMultiplo" @class(['d-none' => $operacionActual !== 'redondeo'])>
                                <label for="redondeo_multiplo" class="form-label fw-semibold">
                                    Múltiplo de redondeo <span class="text-danger" aria-hidden="true">*</span>
                                </label>

                                <div class="input-group has-validation">
                                    <span class="input-group-text">$</span>
                                    <input type="text" inputmode="decimal" class="form-control" id="redondeo_multiplo"
                                        name="redondeo_multiplo" value="{{ request('redondeo_multiplo') }}"
                                        placeholder="Ej: 100 o 1000" aria-describedby="ayudaMultiploAjuste">
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
                                    <input class="form-check-input mt-1" type="radio" name="alcance" id="alcanceRecorte"
                                        value="recorte" @checked($alcance === 'recorte')>
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
                                    <input class="form-check-input mt-1" type="radio" name="alcance" id="alcanceTodos"
                                        value="todos" @checked($alcance === 'todos')>
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

                            {{-- Cada selector pide su lista a la API con su propio permiso: sin él, no se ofrece. --}}
                            <div class="row g-3">
                                @can('categoria.ver')
                                    <div class="col-12 col-md-4">
                                        <label for="categoria_id" class="form-label">Categoría</label>
                                        <select class="form-select" id="categoria_id" name="categoria_id"
                                            data-buscable="jerarquia" data-buscable-diferido data-opciones="categorias"
                                            data-seleccionada="{{ request('categoria_id') }}" aria-describedby="ayudaCategoriaAjuste">
                                            <option value="">Todas las categorías</option>
                                        </select>

                                        <div id="ayudaCategoriaAjuste" class="form-text">
                                            Incluye automáticamente sus subcategorías.
                                        </div>
                                    </div>
                                @endcan

                                @can('marca.ver')
                                    <div class="col-12 col-md-4">
                                        <label for="marca_id" class="form-label">Marca</label>
                                        <select class="form-select" id="marca_id" name="marca_id" data-buscable
                                            data-buscable-diferido data-opciones="marcas"
                                            data-seleccionada="{{ request('marca_id') }}">
                                            <option value="">Todas las marcas</option>
                                        </select>
                                    </div>
                                @endcan

                                @can('proveedor.ver')
                                    <div class="col-12 col-md-4">
                                        <label for="proveedor_id" class="form-label">Proveedor</label>
                                        <select class="form-select" id="proveedor_id" name="proveedor_id" data-buscable
                                            data-buscable-diferido data-opciones="proveedores"
                                            data-seleccionada="{{ request('proveedor_id') }}" aria-describedby="ayudaProveedorAjuste">
                                            <option value="">Todos los proveedores</option>
                                        </select>

                                        <div id="ayudaProveedorAjuste" class="form-text">
                                            Ideal cuando un mayorista actualiza su lista.
                                        </div>
                                    </div>
                                @endcan
                            </div>

                            {{-- Rangos de Costo y Precio agrupados limpiamente --}}
                            <div class="row g-3 mt-1">
                                @foreach (['costo' => 'Rango de costo de compra', 'precio' => 'Rango de precio de venta (contado)'] as $grupo => $rotulo)
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
                                                        <input type="text" inputmode="decimal" class="form-control"
                                                            id="{{ $nombre }}" name="{{ $nombre }}"
                                                            value="{{ request($nombre) }}"
                                                            placeholder="{{ ucfirst($grupo) }} {{ $corto }}"
                                                            aria-label="{{ $rotulo }}, {{ $extremo }}">
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Selección manual desde el catálogo: los ids vienen en la URL, desde el listado de productos. --}}
                            @if ($ids !== [])
                                <div class="alert alert-secondary mt-3 mb-0 small d-flex align-items-center gap-2">
                                    <i class="bi bi-check2-square flex-shrink-0" aria-hidden="true"></i>
                                    <span>
                                        <strong>{{ count($ids) }}</strong> producto(s) seleccionados manualmente desde
                                        el catálogo.
                                        <a href="{{ route('precios.ajuste') }}" class="text-decoration-none ms-1">Limpiar
                                            selección</a>.
                                    </span>
                                </div>

                                @foreach ($ids as $id)
                                    <input type="hidden" name="ids[]" value="{{ $id }}">
                                @endforeach
                            @endif
                        </div>
                    </div>

                    {{-- Salvaguarda: Casilla de Precios Manuales. El oculto la manda en 0 si no se marca: la API la exige. --}}
                    <div class="form-check pt-2">
                        <input type="hidden" name="incluir_manuales" value="0">
                        <input class="form-check-input" type="checkbox" value="1" id="incluir_manuales"
                            name="incluir_manuales" @checked(request('incluir_manuales') === '1') aria-describedby="ayudaManuales">
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

        {{-- PASO 3: PREVISUALIZACIÓN Y CONFIRMACIÓN. Nace oculto; lo muestra el JS cuando la URL pide previsualizar. --}}
        <section class="card border-0 shadow-sm mb-4" data-vista-previa hidden>
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
                <p class="text-body-secondary small mb-3" data-campo="criterio_texto"></p>

                {{-- Tarjetas métricas visuales para fácil comprensión --}}
                <div class="row g-2 mb-3" data-conteos hidden>
                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Total analizado</div>
                            <div class="fs-4 fw-bold text-dark" data-conteo="total"></div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-primary-subtle text-center h-100 border-primary-subtle">
                            <div class="text-primary small fw-semibold">Precios a cambiar</div>
                            <div class="fs-4 fw-bold text-primary" data-conteo="alcanzados"></div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Sin modificaciones</div>
                            <div class="fs-4 fw-bold text-secondary" data-conteo="sin_cambio"></div>
                        </div>
                    </div>

                    <div class="col-6 col-md">
                        <div class="p-2 border rounded-3 text-center h-100 bg-light" data-tarjeta-sin-costo>
                            <div class="small text-body-secondary">Sin costo cargado</div>
                            <div class="fs-4 fw-bold text-secondary" data-conteo="sin_costo"></div>
                        </div>
                    </div>

                    <div class="col-12 col-md">
                        <div class="p-2 border rounded-3 bg-light text-center h-100">
                            <div class="text-body-secondary small">Protegidos (precio manual)</div>
                            <div class="fs-4 fw-bold text-secondary" data-conteo="manuales_afuera"></div>
                        </div>
                    </div>
                </div>

                {{-- Alerta crítica: Precios por debajo del costo --}}
                <div class="alert alert-warning mb-3 d-flex align-items-start gap-2" role="alert" data-aviso-bajo-costo hidden>
                    <i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 fs-5" aria-hidden="true"></i>
                    <div>
                        <strong>¡Atención! <span data-conteo="bajo_costo"></span> producto(s) quedarían por debajo del costo de
                            compra.</strong>
                        Se venderían a pérdida. Están destacados en la tabla inferior con una insignia amarilla para que
                        puedas revisarlos antes de aplicar.
                    </div>
                </div>

                <div id="avisoManualesAfuera" class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3"
                    data-aviso-manuales hidden>
                    <i class="bi bi-shield-lock-fill text-info flex-shrink-0"></i>
                    <div>
                        Hay <strong><span data-conteo="manuales_afuera"></span> producto(s)</strong> con precio manual que no
                        serán modificados.
                        Si querés incluirlos en este ajuste, marcá la casilla de precio manual arriba y volvé a
                        previsualizar.
                    </div>
                </div>

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
                        <tbody data-listado-filas data-columnas="{{ auth()->user()?->can('precio.ver_costo') ? 7 : 6 }}"
                            data-vacio="Ningún producto coincide con el filtro seleccionado. Probá cambiando los criterios o marcá la casilla para incluir productos con precio manual."
                            data-vacio-filtrado="Ningún producto coincide con el filtro seleccionado. Probá cambiando los criterios o marcá la casilla para incluir productos con precio manual.">
                            <tr>
                                <td colspan="7" class="text-center text-body-secondary py-4">Cargando…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Una fila, con un @can por columna o enlace (regla 12). Los textos los pone el JS con textContent. --}}
                <template data-listado-fila>
                    <tr>
                        <td class="font-monospace small text-body-secondary" data-campo="codigo"></td>

                        <td>
                            @can('producto.editar')
                                <a href="{{ route('productos.edit', ['producto' => '__ID__']) }}"
                                    class="text-decoration-none fw-semibold" data-campo="nombre"></a>
                            @else
                                <span class="fw-semibold" data-campo="nombre"></span>
                            @endcan
                        </td>

                        @can('precio.ver_costo')
                            <td class="text-end d-none d-md-table-cell text-body-secondary">
                                <span data-campo="costo"></span>
                                <span class="badge bg-dark-subtle text-dark-emphasis border ms-1" data-costo-nuevo hidden>nuevo</span>
                            </td>
                        @endcan

                        <td class="text-end" data-campo="precio_contado"></td>
                        <td class="text-end fw-bold text-dark" data-campo="nuevo_contado"></td>
                        <td class="text-end d-none d-md-table-cell"><span data-campo="variacion"></span></td>
                        <td class="text-nowrap"><span class="badge" data-campo="resultado"></span></td>
                    </tr>
                </template>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3" data-listado-pie hidden>
                    <p class="text-body-secondary small mb-0" data-listado-resumen></p>
                    <div data-listado-paginas></div>
                </div>

                {{-- Confirmación y Aplicación: se ofrece sólo si hay algo que cambiar. --}}
                @can('precio.ajustar_masivo')
                    <div class="pt-3 border-top mt-3" data-confirmar-ajuste hidden>
                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                            <div class="text-body-secondary small">
                                <i class="bi bi-info-circle me-1"></i> Se aplicará el ajuste únicamente a los
                                <strong data-conteo="alcanzados"></strong> productos cuyo precio se modifica.
                            </div>

                            <button type="button" class="btn btn-acento btn-lg px-4 text-nowrap" data-aplicar-ajuste>
                                <i class="bi bi-check-lg" aria-hidden="true"></i>
                                Confirmar y aplicar a <span data-conteo="alcanzados"></span> producto(s)
                            </button>
                        </div>
                    </div>
                @endcan
            </div>
        </section>
    </div>
@endsection
