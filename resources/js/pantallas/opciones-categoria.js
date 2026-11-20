// Las opciones de un selector de categorías, armadas con lo que trae la API (tramo D). Es el gemelo
// de components/opciones-categoria.blade.php: el texto es la ruta completa, y `data-data` lleva lo
// que el selector con búsqueda necesita para dibujar el árbol.

const orden = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });

/** Ordena por ruta: así cada padre queda antes de sus hijas. */
export function porRuta(categorias) {
    return [...categorias].sort((a, b) => orden.compare(a.ruta, b.ruta));
}

/** Agrega una opción por categoría al final del select y marca la elegida. */
export function llenarOpcionesDeCategoria(select, categorias, { seleccionada = null, prefijo = '' } = {}) {
    categorias.forEach((categoria) => {
        const opcion = document.createElement('option');
        opcion.value = String(categoria.id);
        opcion.textContent = `${prefijo}${categoria.ruta}${categoria.activo ? '' : ' (inactiva)'}`;
        opcion.dataset.data = JSON.stringify({
            nombre: categoria.nombre,
            nivel: categoria.nivel,
            ruta: categoria.ruta,
            inactiva: !categoria.activo,
        });
        opcion.selected = seleccionada !== null && String(seleccionada) === String(categoria.id);
        select.append(opcion);
    });
}
