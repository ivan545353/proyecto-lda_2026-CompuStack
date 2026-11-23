// Los dos paneles, por la API (tramo H): `GET /panel` y `GET /panel/general`. El período del filtro es un GET
// a la misma pantalla; lo que se pide a la API es la consulta de la URL tal cual. Los números, sus
// comparaciones y las dos series vienen hechos: acá sólo se escriben.

import { api, ErrorDeApi } from '../api/cliente';
import { dibujarFacturadoPorDia } from '../componentes/graficos';
import { pesos } from './formato';
import { rellenar } from './listado';

// El ícono acompaña a la palabra de la comparación, nunca la reemplaza (la de `Comparacion::icono()`).
const ICONO = { mas: 'bi-arrow-up-right', menos: 'bi-arrow-down-right', igual: 'bi-dash' };

const diaYMes = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', timeZone: 'UTC' });
const diaMesAno = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' });

/** `vendi.cantidad` → datos.vendi.cantidad. */
const leer = (datos, clave) => clave.split('.').reduce((valor, parte) => valor?.[parte], datos);

const escribir = (valor, formato) => (formato === 'pesos' ? pesos(valor) : String(valor ?? ''));

function mostrarSi(raiz, condiciones) {
    Object.entries(condiciones).forEach(([clave, vale]) => {
        raiz.querySelectorAll(`[data-si="${clave}"]`).forEach((lugar) => {
            lugar.hidden = !vale;
        });
    });
}

function pintarNumeros(raiz, datos) {
    raiz.querySelectorAll('[data-numero]').forEach((lugar) => {
        const valor = escribir(leer(datos, lugar.dataset.numero), lugar.dataset.formato);
        lugar.textContent = valor;
        if (lugar.dataset.rotulo) lugar.setAttribute('aria-label', lugar.dataset.rotulo.replace('__N__', valor));
    });

    raiz.querySelectorAll('[data-comparar]').forEach((lugar) => {
        const comparacion = datos.comparaciones[lugar.dataset.comparar];
        lugar.dataset.comparacion = comparacion.sentido;
        lugar.replaceChildren();

        if (ICONO[comparacion.sentido]) {
            const icono = document.createElement('i');
            icono.className = `bi ${ICONO[comparacion.sentido]}`;
            icono.setAttribute('aria-hidden', 'true');
            lugar.append(icono, ' ');
        }

        lugar.append(comparacion.texto);
    });
}

function filas(raiz, clave, datos, llenar) {
    const plantilla = raiz.querySelector(`template[data-plantilla="${clave}"]`);

    raiz.querySelector(`[data-filas="${clave}"]`).replaceChildren(...datos.map((dato) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);
        llenar(fila, dato);

        return fila;
    }));
}

// Lo propio del panel general: la nota de los borradores, las dos tablas y el gráfico.
function pintarGeneral(raiz, datos) {
    const borradores = datos.borradores_de_reposicion;
    const detalle = raiz.querySelector('[data-detalle]');

    if (detalle) {
        detalle.hidden = borradores === 0;
        detalle.querySelector('[data-texto-detalle]').textContent = borradores === 1
            ? '1 borrador lo generó la reposición y espera aprobación.'
            : `${borradores} borradores los generó la reposición y esperan aprobación.`;
    }

    const conVentas = datos.cantidad > 0 || datos.por_metodo.length > 0;
    mostrarSi(raiz, { vacio: !conVentas, con_ventas: conVentas });
    if (!conVentas) return;

    filas(raiz, 'por_dia', datos.por_dia, (fila, dia) => rellenar(fila, dia.dia, {
        dia: diaMesAno.format(new Date(dia.dia)),
        cantidad: String(dia.cantidad),
        facturado: pesos(dia.facturado),
    }));

    filas(raiz, 'por_metodo', datos.por_metodo, (fila, medio) => {
        rellenar(fila, medio.metodo, {
            metodo_texto: medio.metodo_texto,
            movimientos: String(medio.movimientos),
            neto: pesos(medio.neto),
        });
        fila.querySelector('[data-campo="neto"]').classList.toggle('text-danger-emphasis', Number(medio.neto) < 0);
    });

    dibujarFacturadoPorDia(raiz.querySelector('[data-grafico-dias]'), datos.por_dia.map((dia) => ({
        dia: diaYMes.format(new Date(dia.dia)),
        facturado: Number(dia.facturado),
    })));
}

/** Cualquiera de los dos paneles: pide la ruta del shell con el período de la URL y pinta lo que vuelve. */
export function iniciarPanel() {
    const raiz = document.querySelector('[data-pantalla="panel"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtro-periodo]');
    const consulta = new URLSearchParams();
    ['desde', 'hasta'].forEach((clave) => {
        const valor = new URLSearchParams(window.location.search).get(clave);
        if (valor) consulta.set(clave, valor);
    });

    const ruta = `${raiz.dataset.ruta}${consulta.size ? `?${consulta}` : ''}`;

    api.get(ruta, { formulario }).then(({ datos }) => {
        raiz.querySelector('[data-periodo]').textContent = datos.periodo.texto;

        // Sin fechas en la URL, el filtro muestra las del período que contestó el servidor (el mes en curso).
        formulario.elements.namedItem('desde').value ||= datos.periodo.desde;
        formulario.elements.namedItem('hasta').value ||= datos.periodo.hasta;

        pintarNumeros(raiz, datos);

        if ('presupuestos_abiertos' in datos) {
            mostrarSi(raiz, { vacio: datos.vendi.cantidad === 0 && datos.cobre.movimientos === 0 });
        } else {
            pintarGeneral(raiz, datos);
        }
    }).catch((error) => {
        // Un período inválido ya quedó marcado en su campo (422); otro error de la API ya lo mostró el módulo central.
        if (!(error instanceof ErrorDeApi)) throw error;
    }).finally(() => {
        raiz.removeAttribute('aria-busy');
    });
}
