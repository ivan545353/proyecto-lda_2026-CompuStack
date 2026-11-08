import TomSelect from 'tom-select';

/**
 * Selectores con búsqueda.
 *
 */

const opcionJerarquica = (data, escape) => {
    if (!data.nivel) {
        return `<div>${escape(data.text)}</div>`;
    }
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

/** Caracteres mínimos si la vista no los publica (ver BusquedaRequest::MINIMO). */
const MINIMO_POR_OMISION = 2;

/** Lo que se espera antes de preguntarle al servidor, en milisegundos. */
const ESPERA = 300;

const pesos = new Intl.NumberFormat('es-AR', {
    style: 'currency',
    currency: 'ARS',
    minimumFractionDigits: 2,
});

/** Un resultado del servidor, con las claves que Tom Select espera. */
const aOpcion = (fila) => ({ ...fila, value: String(fila.id), text: fila.texto });

const exactoPrimero = (resultados, texto) => {
    const buscado = texto.trim().toLowerCase();
    const cual = resultados.findIndex(
        (fila) => String(fila.codigo ?? '').toLowerCase() === buscado,
    );

    return cual <= 0
        ? resultados
        : [resultados[cual], ...resultados.filter((_, indice) => indice !== cual)];
};

/**
 * El renglón secundario de un resultado.
 */
const detalleDe = (data, campos, escape) => {
    const partes = [];

    if (campos.includes('precio') && data.precio !== undefined && data.precio !== null) {
        partes.push(pesos.format(Number(data.precio)));
    }

    if (campos.includes('disponible') && data.disponible !== undefined && data.disponible !== null) {
        partes.push(data.disponible > 0
            ? `${data.disponible} disponible${data.disponible === 1 ? '' : 's'}`
            : 'sin stock');
    }

    return partes.length === 0
        ? ''
        : `<span class="ts-detalle">${escape(partes.join(' · '))}</span>`;
};

const opcionRemota = (campos) => (data, escape) =>
    `<div class="ts-opcion-remota"><span>${escape(data.text)}</span>${detalleDe(data, campos, escape)}</div>`;

/** El pie del desplegable, creado una sola vez por selector. */
const pieDe = (buscador) => {
    if (buscador.pieRemoto) {
        return buscador.pieRemoto;
    }

    const pie = document.createElement('div');

    pie.className = 'ts-pie';
    pie.hidden = true;
    // Lo anuncia un lector de pantalla: es un aviso que aparece después de que el
    // usuario escribió, no un rótulo que estaba ahí desde el principio.
    pie.setAttribute('aria-live', 'polite');

    buscador.dropdown.appendChild(pie);
    buscador.pieRemoto = pie;

    return pie;
};

const avisar = (pie, texto, esError = false) => {
    pie.classList.toggle('ts-pie-error', esError);
    pie.hidden = texto === '';
    pie.innerHTML = texto === ''
        ? ''
        : (esError ? '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>' : '') + texto;
};

/**
 * Hace que el selector vuelva a preguntarle al servidor lo que ya preguntó.
 *
 * Tom Select memoriza cada búsqueda por su texto, así que quien cambie el recorte
 * —hoy `data-buscable-excluir`— tiene que olvidar: volver a tipear lo mismo mostraría
 * el resultado de antes del cambio. Es la misma mitad que el `clearOptions()` de
 * `load()`, que existe porque sin puntaje local lo cargado antes también se vería.
 */
export function olvidarBusquedas(select) {
    const buscador = select?.tomselect;

    if (!buscador) {
        return;
    }

    buscador.clearOptions();
    buscador.loadedSearches = {};
}

/**
 * Agrega una fila del servidor al selector y la deja elegida, con las mismas claves que una búsqueda.
 */
export function elegirOpcion(select, fila) {
    const opcion = aOpcion(fila);
    const buscador = select?.tomselect;

    if (!buscador) {
        select?.add(new Option(opcion.text, opcion.value, true, true));
        return;
    }

    buscador.addOption(opcion);
    buscador.setValue(opcion.value);
}

/** Lo que el selector le agrega a su configuración cuando busca en el servidor. */
const configuracionRemota = (select) => {
    const url = select.dataset.buscableUrl;
    const minimo = Number(select.dataset.buscableMinimo) || MINIMO_POR_OMISION;
    const campos = (select.dataset.buscableDetalle ?? 'precio,disponible')
    .split(',')
    .map((campo) => campo.trim());

    let fallo = false;
    let enCurso = null;

    return {
        minimo,

        opciones: {
            valueField: 'value',
            labelField: 'text',
            loadThrottle: ESPERA,
            preload: false,

            shouldLoad: (texto) => texto.trim().length >= minimo,
            score: () => () => 1,

            load(texto, callback) {
                const buscador = this;
                const pie = pieDe(buscador);
                const destino = new URL(select.dataset.buscableUrl, window.location.origin);

                destino.searchParams.set('q', texto.trim());
                (select.dataset.buscableExcluir ?? '')
                    .split(',')
                    .filter((id) => id !== '')
                    .forEach((id) => destino.searchParams.append('excluir[]', id));

                // La respuesta de un tecleo anterior puede llegar después que la del
                // último y dejar en pantalla resultados de otra búsqueda.
                enCurso?.abort();
                enCurso = new AbortController();

                fetch(destino, {
                    signal: enCurso.signal,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                    .then((respuesta) => {
                        if (!respuesta.ok) {
                            throw new Error(String(respuesta.status));
                        }

                        return respuesta.json();
                    })
                    .then((cuerpo) => {
                        const resultados = cuerpo.resultados ?? [];

                        fallo = false;
                        buscador.clearOptions();

                        avisar(pie, cuerpo.hay_mas
                            ? `Hay más de ${resultados.length} coincidencias. Agregá una palabra para afinar la búsqueda.`
                            : '');

                            callback(exactoPrimero(resultados, texto).map(aOpcion));
                    })
                    .catch((error) => {
                        if (error.name === 'AbortError') {
                            return;
                        }

                        fallo = true;
                        delete buscador.loadedSearches[texto];

                        avisar(pie, 'No se pudo buscar. Revisá la conexión y probá de nuevo.', true);
                        callback([]);
                    });
            },

            render: {
                option: opcionRemota(campos),

                loading: () => '<div class="ts-pista">Buscando…</div>',

                not_loading: () => `<div class="ts-pista">Escribí al menos ${minimo} caracteres.</div>`,

                // Si la búsqueda no se pudo hacer, el desplegable no afirma que no
                // hay coincidencias: eso sería un hecho que el servidor no contestó.
                no_results: (data, escape) => (fallo
                    ? null
                    : `<div class="no-results">No hay coincidencias para «${escape(data.input)}».</div>`),
            },

            onDropdownOpen() {
                // El pie sobrevive al cierre del desplegable: si quedó un aviso de la
                // búsqueda anterior, se limpia al abrirlo de nuevo.
                avisar(pieDe(this), '');
            },
        },
    };
};

export function iniciarSelectsBuscables(raiz = document) {
    raiz.querySelectorAll('select[data-buscable]').forEach((select) => {
        if (select.tomselect) {
            return;   // ya iniciado
        }

        const jerarquia = select.dataset.buscable === 'jerarquia';
        const vacia = select.querySelector('option[value=""]');
        const remoto = select.dataset.buscableUrl ? configuracionRemota(select) : null;

        const locales = {
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
        };

        const buscador = new TomSelect(select, {
            ...locales,
            ...(remoto?.opciones ?? {}),
            render: {
                ...locales.render,
                ...(remoto?.opciones.render ?? {}),
            },
        });

        // La ayuda y el error del campo están enlazados al <select> nativo,
        // que queda oculto. Se pasan al elemento que recibe el foco, para que
        // un lector de pantalla los siga anunciando.
        const descripcion = select.getAttribute('aria-describedby')?.trim();
        if (descripcion) {
            (buscador.focus_node ?? buscador.control_input)?.setAttribute('aria-describedby', descripcion);
        }

        buscador.control_input?.setAttribute(
            'placeholder',
            remoto ? `Escribí al menos ${remoto.minimo} caracteres…` : 'Escribí para buscar…',
        );
    });
}