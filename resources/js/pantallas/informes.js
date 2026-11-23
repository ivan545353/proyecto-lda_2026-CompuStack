// Los dos informes de ventas, por la API (tramo H): `GET /informes/vendedores` y `GET /informes/productos`.
// Son listados paginados con el período del panel; el filtro es un GET a la misma pantalla.

import { pesos } from './formato';
import { iniciarListado, rellenar } from './listado';

// Cada informe: cómo se escribe una fila. El puesto lo pone `alCargar`, que sabe en qué página está.
const FILAS = {
    '/informes/vendedores': (fila, dato) => rellenar(fila, dato.usuario_id, {
        vendedor: dato.vendedor,
        cantidad: String(dato.cantidad),
        facturado: pesos(dato.facturado),
        ticket: pesos(dato.ticket),
    }),
    '/informes/productos': (fila, dato) => rellenar(fila, dato.producto_id, {
        nombre: dato.nombre,
        codigo: dato.codigo,
        unidades: String(dato.unidades),
        importe: pesos(dato.importe),
    }),
};

/** El informe de la pantalla: sus filas, su puesto, el período y, en el ranking, la fila de totales. */
export function iniciarInforme() {
    const raiz = document.querySelector('[data-pantalla="informe"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtro-periodo]');
    const ruta = raiz.dataset.ruta;

    iniciarListado(raiz, {
        ruta,
        llenar: FILAS[ruta],
        filtros: ['desde', 'hasta'],
        formulario,
        alCargar: (sobre) => {
            const pie = raiz.querySelector('tfoot[data-total]');
            if (pie) pie.hidden = !sobre?.totales || sobre.total === 0;
            if (!sobre) return;

            raiz.querySelector('[data-periodo]').textContent = sobre.criterios.texto;

            // Sin fechas en la URL, el filtro muestra las del período que contestó el servidor.
            formulario.elements.namedItem('desde').value ||= sobre.criterios.desde;
            formulario.elements.namedItem('hasta').value ||= sobre.criterios.hasta;

            const primero = (sobre.pagina - 1) * sobre.por_pagina;
            raiz.querySelectorAll('[data-listado-filas] [data-campo="puesto"]').forEach((celda, indice) => {
                celda.textContent = String(primero + indice + 1);
            });

            if (pie && sobre.totales) {
                rellenar(pie, '', {
                    cantidad: String(sobre.totales.cantidad),
                    facturado: pesos(sobre.totales.facturado),
                    ticket: pesos(sobre.totales.ticket),
                });
            }
        },
    });
}
