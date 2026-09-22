import TomSelect from 'tom-select';

/**
 * Selectores con búsqueda.
 *
 * Se aplica a todo <select data-buscable>. Es mejora progresiva: el <select>
 * nativo sigue existiendo debajo y es el que se envía con el formulario. Si
 * este archivo no carga, el formulario funciona igual, y el servidor valida
 * exactamente lo mismo.
 *
 * Variantes:
 *   data-buscable              lista plana (marcas, proveedores)
 *   data-buscable="jerarquia"  árbol de categorías: sangría por nivel y, al
 *                              buscar, la ruta completa de cada resultado.
 *                              Las opciones traen su nivel y su ruta en
 *                              data-data (ver el componente Blade
 *                              <x-opciones-categoria>).
 *
 * Cuándo usarlo: en listas que crecen con el uso del sistema. Las listas
 * cerradas y cortas (estado, alícuota, método de pago) quedan nativas: en el
 * celular, el selector del sistema operativo es más cómodo que un buscador
 * que abre el teclado.
 *
 * Pendiente (Fase 6): listas de cientos de filas, como productos y clientes
 * en ventas, se van a buscar en el servidor con un modo remoto
 * (data-buscable-url). Esta función queda preparada para recibirlo.
 */

const opcionJerarquica = (data, escape) => {
    // Opciones especiales ("Todas", "Solo categorías principales"): sin nivel.
    if (!data.nivel) {
        return `<div>${escape(data.text)}</div>`;
    }

    // El camino hasta la categoría: se oculta al navegar el árbol y aparece
    // sólo al buscar, cuando el resultado pierde su contexto visual.
    const separador = ' › ';
    const corte = data.ruta.lastIndexOf(separador);
    const padre = corte >= 0 ? data.ruta.slice(0, corte + separador.length) : '';

    const inactiva = data.inactiva
        ? ' <span class="badge text-bg-secondary ms-1">inactiva</span>'
        : '';

    return `<div class="ts-opcion ts-nivel-${data.nivel}">`
        + `<span class="ts-opcion-padre">${escape(padre)}</span>`
        + `<span class="ts-opcion-nombre">${escape(data.nombre)}</span>`
        + inactiva
        + '</div>';
};

export function iniciarSelectsBuscables(raiz = document) {
    raiz.querySelectorAll('select[data-buscable]').forEach((select) => {
        if (select.tomselect) {
            return;   // ya iniciado
        }

        const jerarquia = select.dataset.buscable === 'jerarquia';
        const vacia = select.querySelector('option[value=""]');

        const buscador = new TomSelect(select, {
            plugins: ['dropdown_input'],   // el buscador dentro del desplegable
            create: false,
            maxOptions: null,              // todas las opciones, con scroll

            // En un campo obligatorio, la opción vacía ("Elegí una categoría")
            // es texto de ayuda, no algo que se pueda elegir. En uno opcional
            // ("Sin marca", "Todas") sí es una opción válida.
            allowEmptyOption: !select.required,
            placeholder: vacia?.textContent.trim() ?? '',

            searchField: ['text'],

            render: {
                ...(jerarquia ? { option: opcionJerarquica } : {}),
                no_results: (data, escape) =>
                    `<div class="no-results">No hay coincidencias para «${escape(data.input)}».</div>`,
            },

            // La clase se usa en el CSS para mostrar la ruta sólo al buscar.
            onType(texto) {
                this.wrapper.classList.toggle('ts-buscando', texto !== '');
            },
            onDropdownClose() {
                this.wrapper.classList.remove('ts-buscando');
            },
        });

        // La ayuda y el error del campo están enlazados al <select> nativo,
        // que queda oculto. Se pasan al elemento que recibe el foco, para que
        // un lector de pantalla los siga anunciando.
        const descripcion = select.getAttribute('aria-describedby')?.trim();
        if (descripcion) {
            (buscador.focus_node ?? buscador.control_input)?.setAttribute('aria-describedby', descripcion);
        }

        buscador.control_input?.setAttribute('placeholder', 'Escribí para buscar…');
    });
}