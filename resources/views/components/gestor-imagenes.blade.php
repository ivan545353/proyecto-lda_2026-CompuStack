{{--
    Gestor de imágenes con vista previa.

    Dos modos, según max:
      max > 1  lista ordenable; envía <campo>[] y <campo>_orden
      max = 1  una sola imagen, con botón de cambiar; envía <campo> y el
               campo de quita indicado en campoQuitar

    Sin JavaScript: las imágenes actuales y un campo de archivo común.
    Ver resources/js/componentes/gestor-imagenes.js.

    Props:
      actuales     rutas guardadas, en orden
      max          cantidad máxima
      maxKb        peso máximo de cada una, en KB
      descripcion  a qué pertenecen, para los textos alternativos
      campo        nombre del campo de archivo (imagenes, logo…)
      campoQuitar  sólo con max = 1: campo que pide quitar la imagen
      etiquetaAgregar  texto de la tarjeta de agregar
--}}
@props([
    'actuales' => [],
    'max',
    'maxKb' => 1024,
    'descripcion' => 'el producto',
    'campo' => 'imagenes',
    'campoQuitar' => null,
    'etiquetaAgregar' => 'Agregar imagen',
])

@php
    $simple = (int) $max === 1;
    $tipos = 'image/jpeg,image/png,image/webp';
    $datosActuales = collect($actuales)->map(fn($ruta) => ['ruta' => $ruta, 'url' => Storage::url($ruta)])->values();
    $mensajes = collect($errors->get($campo))
        ->merge(collect($errors->get($campo . '.*'))->flatten())
        ->merge($errors->get('orden'))
        ->merge(collect($errors->get('orden.*'))->flatten());
@endphp

<div class="js-gestor-imagenes" data-max="{{ $max }}" data-max-kb="{{ $maxKb }}"
    data-tipos="{{ $tipos }}" data-descripcion="{{ $descripcion }}" data-campo="{{ $campo }}"
    data-campo-quitar="{{ $campoQuitar }}" data-etiqueta-agregar="{{ $etiquetaAgregar }}"
    data-actuales="{{ json_encode($datosActuales) }}" data-orden-previo="{{ old($campo . '_orden') }}"
    data-quitar-previo="{{ $campoQuitar && old($campoQuitar) ? '1' : '' }}">

    {{-- Sin JavaScript --}}
    <div class="js-sin-script">
        @if ($actuales)
            <ul class="list-unstyled d-flex flex-wrap gap-3 mb-3">
                @foreach ($actuales as $i => $ruta)
                    <li>
                        <img src="{{ Storage::url($ruta) }}" alt="Imagen {{ $i + 1 }} de {{ $descripcion }}"
                            width="96" height="96" class="rounded border object-fit-cover d-block">
                        @if ($i === 0 && !$simple)
                            <span class="badge text-bg-dark mt-1">Principal</span>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($campoQuitar)
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="{{ $campoQuitar }}"
                        name="{{ $campoQuitar }}" @checked(old($campoQuitar))>
                    <label class="form-check-label" for="{{ $campoQuitar }}">Quitar la imagen actual</label>
                </div>
            @endif
        @endif

        <label for="{{ $campo }}" class="form-label">{{ $actuales ? 'Reemplazar' : 'Agregar' }}</label>
    </div>

    <input type="file" {{ $simple ? '' : 'multiple' }} accept="{{ $tipos }}" id="{{ $campo }}"
        name="{{ $campo }}{{ $simple ? '' : '[]' }}"
        class="form-control {{ $mensajes->isNotEmpty() ? 'is-invalid' : '' }}"
        aria-describedby="ayuda-{{ $campo }} {{ $mensajes->isNotEmpty() ? 'error-' . $campo : '' }}">

    {{-- Con JavaScript: acá se dibujan las tarjetas --}}
    <div data-rol="montaje"></div>

    @if ($mensajes->isNotEmpty())
        <div id="error-{{ $campo }}" class="invalid-feedback d-block">
            @foreach ($mensajes as $mensaje)
                <div>{{ $mensaje }}</div>
            @endforeach
        </div>
    @endif

    <div id="ayuda-{{ $campo }}" class="form-text">
        {{ $simple ? 'Una imagen' : "Hasta {$max} imágenes" }} JPG, PNG o WEBP,
        de hasta {{ $maxKb >= 1024 ? $maxKb / 1024 . ' MB' : $maxKb . ' KB' }}{{ $simple ? '' : ' cada una' }}.
        @unless ($simple)
            <span class="js-sin-script">Las nuevas se agregan al final; la primera es la principal.</span>
            <span class="js-con-script d-none">
                Arrastrá las tarjetas o usá sus botones para ordenarlas: la primera es la principal.
            </span>
        @endunless
        <span class="js-con-script d-none">Los cambios se aplican al guardar.</span>
    </div>

    @if ($errors->any())
        <p class="form-text text-danger-emphasis mb-0 mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Si habías elegido una imagen nueva, volvé a elegirla: por seguridad, el navegador no la conserva.
        </p>
    @endif
</div>
