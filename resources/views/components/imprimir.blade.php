{{--
    El botón «Imprimir» de un listado y su diálogo de opciones.

    «Imprimir» es un enlace con los filtros puestos: sin JavaScript, y con él, imprime con
    las opciones por omisión en un clic. Al lado, el botón que abre «Opciones de
    impresión» nace con `hidden` y lo muestra componentes/impresion.js. El diálogo se rinde
    al final del <body> con @push, porque el botón vive adentro del formulario de filtros
    y un <form> no puede contener otro.

    Props:
      ruta     el nombre de la ruta de impresión del módulo
      listado  la definición del módulo, en App\Impresion
      chico    botones btn-sm, como el pie de filtros de casi todos los listados
--}}
@props(['ruta', 'listado', 'chico' => true])

@use('App\Impresion\Opciones')
@use('App\Services\ImpresionService')
@use('App\Support\Importe')

@php
    $columnas = $listado->columnasPara(auth()->user());
    $grupos = $listado->agrupaciones();
    $filtros = request()->except([
        'page',
        'formato',
        'columnas',
        'orientacion',
        'hoja',
        'densidad',
        'agrupar',
        'salto',
    ]);
    $tamano = $chico ? 'btn-sm' : '';
@endphp

<div {{ $attributes->merge(['class' => 'btn-group']) }} role="group" aria-label="Imprimir el listado">
    {{-- Lleva los filtros puestos y no la página: el papel no pagina. --}}
    <a href="{{ route($ruta, request()->except('page')) }}" data-imprimir
        class="btn {{ $tamano }} btn-outline-dark d-inline-flex align-items-center justify-content-center gap-1">
        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
    </a>

    <button type="button" class="btn {{ $tamano }} btn-outline-dark" data-bs-toggle="modal"
        data-bs-target="#opcionesImpresion" data-impresion-abre hidden aria-label="Opciones de impresión">
        <i class="bi bi-sliders" aria-hidden="true"></i>
    </button>
</div>

@push('modales')
    <div class="modal fade" id="opcionesImpresion" tabindex="-1" aria-labelledby="opcionesImpresionTitulo"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <form method="GET" action="{{ route($ruta) }}" class="modal-content" data-impresion>
                {{-- Los filtros que la pantalla tiene aplicados: el papel imprime lo que el listado muestra. --}}
                @foreach ($filtros as $clave => $valor)
                    @if (is_scalar($valor) && $valor !== '')
                        <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
                    @endif
                @endforeach

                <div class="modal-header">
                    <h2 class="modal-title h5" id="opcionesImpresionTitulo">Opciones de impresión</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">
                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 fw-semibold">Formato</legend>

                        @foreach (Opciones::FORMATOS as $valor => $rotulo)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="formato"
                                    id="impresion-formato-{{ $valor }}" value="{{ $valor }}"
                                    @checked($valor === 'pdf') aria-describedby="impresion-ayuda-formato">
                                <label class="form-check-label"
                                    for="impresion-formato-{{ $valor }}">{{ $rotulo }}</label>
                            </div>
                        @endforeach

                        <div id="impresion-ayuda-formato" class="form-text">
                            El PDF imprime hasta {{ Importe::numero(ImpresionService::TOPE_PDF, 0) }} filas; la planilla,
                            todas.
                        </div>
                    </fieldset>

                    <fieldset class="mb-3">
                        <legend class="form-label fs-6 fw-semibold">Columnas</legend>

                        @foreach ($columnas as $columna)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="columnas[]"
                                    id="impresion-columna-{{ $columna->clave }}" value="{{ $columna->clave }}"
                                    @checked($columna->porOmision)>
                                <label class="form-check-label" for="impresion-columna-{{ $columna->clave }}">
                                    {{ $columna->rotulo }}
                                </label>
                            </div>
                        @endforeach

                        {{-- Vacío mientras haya una columna elegida: el texto lo pone el componente y se anuncia. --}}
                        <p class="small text-danger mb-0 mt-1" aria-live="polite"
                            data-impresion-sin-columnas="Elegí al menos una columna."></p>
                    </fieldset>

                    {{-- Todo lo de la hoja. Con la planilla elegida se apaga entero, y lo apagado no viaja. --}}
                    <fieldset data-impresion-papel>
                        <legend class="form-label fs-6 fw-semibold">Papel</legend>

                        <p class="small text-body-secondary" aria-live="polite"
                            data-impresion-sin-papel="La planilla no tiene hojas: estas opciones son del PDF."></p>

                        <div class="row g-2">
                            <div class="col-12 col-sm-4">
                                <label for="impresion-orientacion" class="form-label">Orientación</label>
                                <select class="form-select" id="impresion-orientacion" name="orientacion">
                                    @foreach (Opciones::ORIENTACIONES as $valor => $rotulo)
                                        <option value="{{ $valor }}" @selected($valor === $listado->orientacion())>
                                            {{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label for="impresion-hoja" class="form-label">Hoja</label>
                                <select class="form-select" id="impresion-hoja" name="hoja">
                                    @foreach (Opciones::HOJAS as $valor => $rotulo)
                                        <option value="{{ $valor }}" @selected($valor === 'a4')>
                                            {{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-sm-4">
                                <label for="impresion-densidad" class="form-label">Densidad</label>
                                <select class="form-select" id="impresion-densidad" name="densidad"
                                    aria-describedby="impresion-ayuda-densidad">
                                    @foreach (Opciones::DENSIDADES as $valor => $rotulo)
                                        <option value="{{ $valor }}" @selected($valor === 'normal')>
                                            {{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div id="impresion-ayuda-densidad" class="col-12 form-text mt-1">
                                Compacta mete casi el doble de filas por hoja.
                            </div>

                            @if (count($grupos))
                                <div class="col-12 col-sm-6">
                                    <label for="impresion-agrupar" class="form-label">Agrupar</label>
                                    <select class="form-select" id="impresion-agrupar" name="agrupar"
                                        aria-describedby="impresion-ayuda-agrupar">
                                        <option value="">Sin agrupar</option>
                                        @foreach ($grupos as $clave => $agrupacion)
                                            <option value="{{ $clave }}">Por
                                                {{ mb_strtolower($agrupacion->rotulo) }}</option>
                                        @endforeach
                                    </select>
                                    <div id="impresion-ayuda-agrupar" class="form-text">Con un subtotal por grupo.</div>
                                </div>

                                <div class="col-12 col-sm-6 d-flex align-items-sm-center">
                                    <div class="form-check mt-sm-3">
                                        <input class="form-check-input" type="checkbox" name="salto"
                                            id="impresion-salto" value="1" disabled>
                                        <label class="form-check-label" for="impresion-salto">Cada grupo en una hoja
                                            nueva</label>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </fieldset>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-acento px-4">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                    </button>
                </div>
            </form>
        </div>
    </div>
@endpush
