{{--
    Gestor de imágenes con vista previa, orden y quita.

    Sin JavaScript: muestra las actuales y un campo de archivo común; las
    nuevas se agregan al final. Con JavaScript: tarjetas, arrastre, quitar con
    deshacer (ver resources/js/componentes/gestor-imagenes.js).

    Props:
      actuales     rutas de las imágenes guardadas, en orden
      max          cantidad máxima de imágenes
      maxKb        peso máximo de cada una, en KB
      descripcion  a qué pertenecen, para los textos alternativos
--}}
@props(['actuales' => [], 'max', 'maxKb' => 1024, 'descripcion' => 'el producto'])

@php
    $tipos = 'image/jpeg,image/png,image/webp';
    $datosActuales = collect($actuales)
        ->map(fn ($ruta) => ['ruta' => $ruta, 'url' => Storage::url($ruta)])
        ->values();
    $mensajes = collect($errors->get('imagenes'))
        ->merge(collect($errors->get('imagenes.*'))->flatten())
        ->merge($errors->get('orden'))
        ->merge(collect($errors->get('orden.*'))->flatten());
@endphp

<div class="js-gestor-imagenes"
    data-max="{{ $max }}"
    data-max-kb="{{ $maxKb }}"
    data-tipos="{{ $tipos }}"
    data-descripcion="{{ $descripcion }}"
    data-actuales="{{ json_encode($datosActuales) }}"
    data-orden-previo="{{ old('imagenes_orden') }}">

    {{-- Sin JavaScript --}}
    <div class="js-sin-script">
        @if ($actuales)
            <ul class="list-unstyled d-flex flex-wrap gap-3 mb-3">
                @foreach ($actuales as $i => $ruta)
                    <li>
                        <img src="{{ Storage::url($ruta) }}" alt="Imagen {{ $i + 1 }} de {{ $descripcion }}"
                            width="96" height="96" class="rounded border object-fit-cover d-block">
                        @if ($i === 0)
                            <span class="badge text-bg-dark mt-1">Principal</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        <label for="imagenes" class="form-label">Agregar imágenes</label>
    </div>

    <input type="file" multiple accept="{{ $tipos }}" id="imagenes" name="imagenes[]"
        class="form-control {{ $mensajes->isNotEmpty() ? 'is-invalid' : '' }}"
        aria-describedby="ayudaImagenes {{ $mensajes->isNotEmpty() ? 'errorImagenes' : '' }}">

    {{-- Con JavaScript: acá se dibujan las tarjetas --}}
    <div data-rol="montaje"></div>

    @if ($mensajes->isNotEmpty())
        <div id="errorImagenes" class="invalid-feedback d-block">
            @foreach ($mensajes as $mensaje)
                <div>{{ $mensaje }}</div>
            @endforeach
        </div>
    @endif

    <div id="ayudaImagenes" class="form-text">
        Hasta {{ $max }} imágenes JPG, PNG o WEBP, de hasta {{ $maxKb >= 1024 ? ($maxKb / 1024).' MB' : $maxKb.' KB' }} cada una.
        <span class="js-sin-script">Las nuevas se agregan al final; la primera es la principal.</span>
        <span class="js-con-script d-none">
            Arrastrá las tarjetas o usá sus botones para ordenarlas: la primera es la principal.
            Los cambios se aplican al guardar.
        </span>
    </div>

    {{-- El navegador no permite volver a completar un campo de archivo. --}}
    @if ($errors->any())
        <p class="form-text text-danger-emphasis mb-0 mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Si habías agregado imágenes nuevas, volvé a elegirlas: por seguridad, el navegador no las conserva.
            Lo que hiciste con las imágenes ya guardadas se mantiene.
        </p>
    @endif
</div>