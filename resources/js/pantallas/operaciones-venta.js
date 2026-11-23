// El cobro, la entrega parcial y la devolución de una venta, por la API (tramo H). Las tres leen la ficha
// (`GET /ventas/{venta}`, HL-128) y mandan su operación al endpoint; al terminar vuelven a la ficha con el
// mensaje del servidor. Si el servidor rechaza, la pantalla no se recarga: queda lo que se cargó.

import { api, ErrorDeApi } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarCobro } from '../componentes/cobro';
import { numero, paraFormulario, pesos } from './formato';
import { rellenar } from './listado';

/** Muestra u oculta cada `[data-si="clave"]` según su condición. */
function mostrarSi(raiz, condiciones) {
    Object.entries(condiciones).forEach(([clave, vale]) => {
        raiz.querySelectorAll(`[data-si="${clave}"]`).forEach((lugar) => {
            lugar.hidden = !vale;
        });
    });
}

/** Pide la ficha, la pasa a `pintar` y levanta el estado de carga, pase lo que pase. */
function cargarFicha(pantalla, pintar) {
    api.get(`/ventas/${pantalla.dataset.venta}`).then(({ datos }) => {
        rellenar(pantalla, datos.id, { numero: datos.numero, cliente_texto: datos.cliente_texto });
        document.title = `${document.title} — ${datos.numero}`;
        pintar(datos);
    }).catch((error) => {
        // Un error de la API ya lo mostró el módulo central; uno de programación no se calla.
        if (!(error instanceof ErrorDeApi)) throw error;
    }).finally(() => {
        pantalla.querySelector('[data-cargando]').hidden = true;
        pantalla.removeAttribute('aria-busy');
    });
}

/** Manda la operación; si sale, vuelve a la ficha con el mensaje. Si no, el módulo central ya lo mostró. */
function alMandar(pantalla, formulario, armar) {
    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        if (formulario.dataset.confirmar && !window.confirm(formulario.dataset.confirmar)) return;

        limpiarErrores(formulario);
        const { ruta, datos } = armar();
        const liberar = cargando(formulario.querySelector('button[type="submit"]'));

        try {
            const respuesta = await api.post(`/ventas/${pantalla.dataset.venta}/${ruta}`, datos, { formulario });
            completadoAlVolver(respuesta.mensaje, pantalla.dataset.ficha);
        } catch (error) {
            liberar();
            if (!(error instanceof ErrorDeApi)) throw error;
        }
    });
}

// ------------------------------------------------------------------
// El cobro
// ------------------------------------------------------------------

function pintarCobro(pantalla, venta) {
    const formulario = pantalla.querySelector('form[data-cobro]');
    const principal = venta.cobro?.principal ?? 'efectivo';
    const problemas = venta.cobro?.problemas ?? [];

    rellenar(pantalla, venta.id, {
        total: pesos(venta.importes.total),
        pagado: pesos(venta.importes.pagado),
        saldo: pesos(venta.importes.saldo),
    });
    mostrarSi(pantalla, { pagado: Number(venta.importes.pagado) !== 0 });

    const aviso = pantalla.querySelector('[data-problemas]');
    pantalla.querySelector('[data-lista-problemas]').replaceChildren(...problemas.map((texto) => {
        const item = document.createElement('li');
        item.textContent = texto;

        return item;
    }));
    aviso.hidden = problemas.length === 0;

    // El medio principal sube arriba, con su ayuda y el saldo propuesto; los billetes, sólo con efectivo.
    const lugar = pantalla.querySelector('[data-medio-principal]');
    const medio = pantalla.querySelector(`[data-medio="${principal}"]`);
    const campo = medio.querySelector('[data-cobro-monto]');
    lugar.prepend(medio);
    medio.querySelector('[data-ayuda-medio]').textContent = lugar.dataset[`ayuda${principal[0].toUpperCase()}${principal.slice(1)}`] ?? '';

    if (principal !== 'efectivo') pantalla.querySelector('[data-cobro-billetes]')?.remove();

    formulario.dataset.cobroSaldo = venta.importes.saldo;
    if (Number(venta.importes.saldo) > 0) {
        campo.value = paraFormulario(venta.importes.saldo);
        campo.dataset.cobroPropuesto = '';
    }

    pantalla.querySelector('[data-ficha]').hidden = false;
    iniciarCobro(pantalla);

    // Con un aviso arriba, el foco no se lo roba el monto: primero hay que leer qué va a fallar.
    if (problemas.length === 0) campo.focus();
}

/** La pantalla de cobro: la propuesta sale de la ficha, y cobrar es `POST /ventas/{venta}/pagos`. */
export function iniciarCobroDeVenta() {
    const pantalla = document.querySelector('[data-pantalla="cobro-venta"]');
    if (!pantalla) return;

    const formulario = pantalla.querySelector('form[data-cobro]');

    cargarFicha(pantalla, (venta) => pintarCobro(pantalla, venta));

    // Todos los medios, en el orden de la pantalla y vacíos si no entró nada: el índice es el del `name`.
    alMandar(pantalla, formulario, () => ({
        ruta: 'pagos',
        datos: {
            pagos: [...formulario.querySelectorAll('[data-cobro-monto]')]
                .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
                .map((campo) => ({ metodo: campo.dataset.metodo, monto: campo.value })),
        },
    }));
}

// ------------------------------------------------------------------
// La entrega parcial y la devolución: una cantidad por renglón
// ------------------------------------------------------------------

function pintarCantidades(pantalla, venta, deLinea) {
    const formulario = pantalla.querySelector('form[data-formulario-cantidades]');
    const plantilla = pantalla.querySelector('template[data-plantilla-linea]');
    let pendientes = 0;

    pantalla.querySelector('[data-lineas]').replaceChildren(...venta.lineas.map((linea) => {
        const { hecho, pendiente, verbo } = deLinea(linea);
        const fila = plantilla.content.firstElementChild.cloneNode(true);
        fila.innerHTML = fila.innerHTML.replaceAll('__ID__', String(linea.id));
        pendientes += pendiente;

        rellenar(fila, linea.id, {
            descripcion: linea.descripcion,
            codigo: linea.producto.codigo,
            cantidad: String(linea.cantidad),
            hecho: String(hecho),
            pendiente: String(pendiente),
            precio_unitario: pesos(linea.precio_unitario),
        });

        // El `name` es la clave de error de la API: un 422 de `cantidades.12` cae en su campo.
        const campo = fila.querySelector('[data-cantidad]');
        campo.name = `cantidades.${linea.id}`;
        campo.max = String(pendiente);
        fila.querySelector('[data-rotulo-cantidad]').textContent = `Unidades de ${linea.descripcion} que ${verbo}`;

        if (pendiente === 0) fila.querySelector('[data-si="abierta"]').remove();
        else fila.querySelector('[data-si="completa"]').remove();

        return fila;
    }));

    rellenar(pantalla, venta.id, {
        pendientes: String(pendientes),
        total: pesos(venta.importes.total),
        pagado: pesos(venta.importes.pagado),
        descuento_porcentaje: numero(venta.importes.descuento_porcentaje, 2),
    });
    mostrarSi(pantalla, { descuento: Number(venta.importes.descuento) > 0 });

    formulario.hidden = false;
}

function cantidades(formulario) {
    return Object.fromEntries([...formulario.querySelectorAll('[data-cantidad]')]
        .map((campo) => [campo.name.split('.')[1], campo.value]));
}

/** La entrega en partes: `POST /ventas/{venta}/entregas` con lo que sale ahora de cada renglón. */
export function iniciarEntregaParcial() {
    const pantalla = document.querySelector('[data-pantalla="entrega-venta"]');
    if (!pantalla) return;

    const formulario = pantalla.querySelector('form[data-formulario-cantidades]');

    cargarFicha(pantalla, (venta) => pintarCantidades(pantalla, venta, (linea) => ({
        hecho: linea.cantidad - linea.pendiente_de_entregar,
        pendiente: linea.pendiente_de_entregar,
        verbo: 'salen ahora',
    })));

    alMandar(pantalla, formulario, () => ({ ruta: 'entregas', datos: { cantidades: cantidades(formulario) } }));
}

/** La devolución: `POST /ventas/{venta}/devoluciones`; el monto lo calcula el servidor. */
export function iniciarDevolucion() {
    const pantalla = document.querySelector('[data-pantalla="devolucion-venta"]');
    if (!pantalla) return;

    const formulario = pantalla.querySelector('form[data-formulario-cantidades]');

    cargarFicha(pantalla, (venta) => {
        pintarCantidades(pantalla, venta, (linea) => ({
            hecho: linea.cantidad_devuelta,
            pendiente: linea.pendiente_de_devolver,
            verbo: 'vuelven',
        }));

        if (venta.devolucion?.sugerido) formulario.elements.namedItem('metodo').value = venta.devolucion.sugerido;
    });

    alMandar(pantalla, formulario, () => ({
        ruta: 'devoluciones',
        datos: {
            cantidades: cantidades(formulario),
            metodo: formulario.elements.namedItem('metodo').value,
            motivo: formulario.elements.namedItem('motivo').value,
        },
    }));
}
