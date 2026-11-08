{{--
    Opciones de un <select> de categorías, para usar con data-buscable="jerarquia".

    El texto de cada opción es la ruta completa: es lo que se ve si el
    JavaScript no carga, y es lo que recorre la búsqueda. data-data lleva lo
    que el componente JS necesita para dibujar el árbol.

    Las categorías tienen que venir ordenadas por ruta y con padre.padre
    cargado: así cada padre queda antes de sus hijas y el nivel se calcula
    sin consultas.

    Props:
      categorias    colección de Categoria
      seleccionada  id elegido (acepta el string que devuelve old())
      prefijo       texto delante de la ruta, por ejemplo "Subcategorías de "
--}}
@props(['categorias', 'seleccionada' => null, 'prefijo' => ''])

@foreach ($categorias as $categoria)
    <option value="{{ $categoria->id }}" @selected((string) $seleccionada === (string) $categoria->id)
        data-data="{{ json_encode([
            'nombre' => $categoria->nombre,
            'nivel' => $categoria->nivelCargado(),
            'ruta' => $categoria->ruta,
            'inactiva' => !$categoria->activo,
        ]) }}">
        {{ $prefijo }}{{ $categoria->ruta }}{{ $categoria->activo ? '' : ' (inactiva)' }}</option>
@endforeach
