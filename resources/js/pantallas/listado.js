// El listado de una pantalla de la administración, por la API (tramo D): la molde de marcas,
// categorías y productos. El shell trae la tabla y la plantilla de la fila, con un `@can` por
// acción (regla 12); acá se piden los datos, se clonan las filas y se arma el paginado.
//
// Los filtros y las páginas siguen siendo enlaces GET a la misma pantalla, como siempre: se
// conserva la navegación, y lo que se pide a la API es la consulta de la URL tal cual.
//
// Marcado del shell:
//   [data-listado-filas]          el <tbody>; data-columnas, data-vacio, data-vacio-filtrado y
//                                 data-ver-todos (el texto del enlace que saca los filtros)
//   template[data-listado-fila]   una fila; los textos en [data-campo], los enlaces con __ID__
//   [data-listado-pie]            nace oculto; adentro [data-listado-resumen] y [data-listado-paginas]
//   [data-listado-eliminar]       botón de la fila: data-ruta (de la API) y data-confirmar

import { api } from '../api/cliente';
import { cargando, completado } from '../api/estados';

/** Pone cada texto en su `[data-campo]` y el id de la fila en sus enlaces y rutas. */
export function rellenar(fila, id, textos) {
    Object.entries(textos).forEach(([campo, texto]) => {
        fila.querySelectorAll(`[data-campo="${campo}"]`).forEach((lugar) => {
            lugar.textContent = texto ?? '';
        });
    });

    fila.querySelectorAll('[href*="__ID__"]').forEach((enlace) => {
        enlace.href = enlace.getAttribute('href').replaceAll('__ID__', id);
    });
    fila.querySelectorAll('[data-ruta*="__ID__"]').forEach((boton) => {
        boton.dataset.ruta = boton.dataset.ruta.replaceAll('__ID__', id);
    });
}

/**
 * Arranca el listado. `ruta` es la de la API; `llenar(fila, dato)` completa una fila clonada;
 * `filtros` son las claves de la URL que cuentan como filtro, para el texto de «sin resultados».
 * `alCargar(sobre)` corre después de cada carga, con o sin filas. `formulario`, si lo hay, es donde
 * el módulo central marca un 422 de la consulta (tramo F). Devuelve `{ recargar }`.
 */
export function iniciarListado(raiz, { ruta, llenar, filtros = [], alCargar = () => {}, formulario = null }) {
    const filas = raiz.querySelector('[data-listado-filas]');
    const plantilla = raiz.querySelector('template[data-listado-fila]');
    const pie = raiz.querySelector('[data-listado-pie]');
    const resumen = raiz.querySelector('[data-listado-resumen]');
    const paginas = raiz.querySelector('[data-listado-paginas]');
    const columnas = Number(filas.dataset.columnas || 1);
    let pedido = null;

    const consulta = () => new URLSearchParams(window.location.search);
    const hayFiltros = () => filtros.some((clave) => consulta().get(clave));

    // Una fila que ocupa toda la tabla, con un texto y, si hay filtros, el enlace para sacarlos.
    function mensaje(texto, conLimpiar = false) {
        const celda = document.createElement('td');
        celda.colSpan = columnas;
        celda.className = 'text-center text-body-secondary py-4';
        celda.textContent = texto;

        if (conLimpiar) {
            const limpiar = document.createElement('a');
            limpiar.href = window.location.pathname;
            limpiar.textContent = filas.dataset.verTodos || 'Ver todas';
            celda.append(' ', limpiar, '.');
        }

        const fila = document.createElement('tr');
        fila.append(celda);
        filas.replaceChildren(fila);
    }

    function enlaceDePagina(numero, texto, { activa = false, apagada = false, etiqueta = null } = {}) {
        const item = document.createElement('li');
        item.className = `page-item${activa ? ' active' : ''}${apagada ? ' disabled' : ''}`;

        const enlace = document.createElement(apagada || activa ? 'span' : 'a');
        enlace.className = 'page-link';
        enlace.textContent = texto;
        if (etiqueta) enlace.setAttribute('aria-label', etiqueta);
        if (activa) enlace.setAttribute('aria-current', 'page');

        if (enlace.tagName === 'A') {
            const parametros = consulta();
            parametros.set('pagina', numero);
            enlace.href = `?${parametros}`;
        }

        item.append(enlace);

        return item;
    }

    function pintarPie(sobre) {
        const desde = (sobre.pagina - 1) * sobre.por_pagina + 1;
        const hasta = desde + sobre.datos.length - 1;
        resumen.textContent = `Mostrando ${desde}–${hasta} de ${sobre.total}.`;

        const lista = document.createElement('ul');
        lista.className = 'pagination pagination-sm mb-0';

        if (sobre.ultima_pagina > 1) {
            lista.append(enlaceDePagina(sobre.pagina - 1, '‹', { apagada: sobre.pagina === 1, etiqueta: 'Página anterior' }));

            // Las páginas de alrededor de la actual, con la primera y la última siempre a mano.
            const cerca = new Set([1, sobre.ultima_pagina]);
            for (let n = sobre.pagina - 2; n <= sobre.pagina + 2; n++) {
                if (n >= 1 && n <= sobre.ultima_pagina) cerca.add(n);
            }

            let anterior = 0;
            [...cerca].sort((a, b) => a - b).forEach((n) => {
                if (n - anterior > 1) lista.append(enlaceDePagina(null, '…', { apagada: true }));
                lista.append(enlaceDePagina(n, String(n), { activa: n === sobre.pagina }));
                anterior = n;
            });

            lista.append(enlaceDePagina(sobre.pagina + 1, '›', {
                apagada: sobre.pagina === sobre.ultima_pagina,
                etiqueta: 'Página siguiente',
            }));
        }

        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Páginas del listado');
        nav.append(lista);
        paginas.replaceChildren(nav);
        pie.hidden = false;
    }

    async function recargar() {
        pedido?.abort();
        pedido = new AbortController();

        filas.setAttribute('aria-busy', 'true');
        mensaje('Cargando…');

        let sobre;

        try {
            const separador = ruta.includes('?') ? '&' : '?';
            sobre = await api.get(`${ruta}${separador}${consulta()}`, { senal: pedido.signal, formulario });
        } catch (error) {
            if (error?.name === 'AbortError') return;
            // El aviso ya lo dio el módulo central: la tabla dice que no hay lista que mostrar.
            mensaje('No se pudo cargar el listado.');
            pie.hidden = true;
            alCargar(null);

            return;
        } finally {
            filas.removeAttribute('aria-busy');
        }

        if (sobre.datos.length === 0) {
            const filtrado = hayFiltros();
            mensaje(filtrado ? filas.dataset.vacioFiltrado : filas.dataset.vacio, filtrado);
            pie.hidden = true;
            alCargar(sobre);

            return;
        }

        filas.replaceChildren(...sobre.datos.map((dato) => {
            const fila = plantilla.content.firstElementChild.cloneNode(true);
            llenar(fila, dato);

            return fila;
        }));
        pintarPie(sobre);
        alCargar(sobre);
    }

    // La baja de una fila: confirma con el texto que armó `llenar`, avisa lo que pasó y recarga.
    filas.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('[data-listado-eliminar]');
        if (!boton || !window.confirm(boton.dataset.confirmar)) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.delete(boton.dataset.ruta);
            completado(respuesta?.mensaje);
            await recargar();
        } catch {
            // El módulo central ya lo mostró.
            liberar();
        }
    });

    recargar();

    return { recargar };
}

/**
 * Todas las filas de un listado, página por página (regla 15: la API siempre pagina). Para las
 * opciones de un selector, que son pocas: categorías, marcas. Pide de a la página más grande.
 */
export async function todasLasPaginas(ruta, { senal = null } = {}) {
    const filas = [];
    const separador = ruta.includes('?') ? '&' : '?';

    for (let pagina = 1; ; pagina++) {
        const sobre = await api.get(`${ruta}${separador}pagina=${pagina}&por_pagina=50`, { senal });
        filas.push(...sobre.datos);

        if (pagina >= sobre.ultima_pagina) return filas;
    }
}
