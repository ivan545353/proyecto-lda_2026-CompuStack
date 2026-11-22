// El ajuste masivo de precios y su historial, por la API (tramo F de la Etapa 3).
//
// La vista previa sigue siendo un GET a la misma pantalla: lo que el operador eligió queda en la URL,
// y el JS le pide a la API esa misma consulta. Aplicar manda **lo que se previsualizó** (la URL), no lo
// que el formulario tenga ahora: si alguien cambió un campo después de mirar, lo que se escribe es lo
// que vio, nunca otra cosa.

import { api } from '../api/cliente';
import { cargando, completado, completadoAlVolver } from '../api/estados';
import { fechaHora, numero, pesos } from './formato';
import { iniciarListado, rellenar } from './listado';
import { cargarFiltro } from './productos';

// Qué bloque muestra cada operación, y qué se le explica al elegirla.
const OPERACIONES = {
    precio: { bloque: 'campoPorcentaje', descripcion: 'Modifica el precio de venta actual en el porcentaje indicado, sin tocar el costo.' },
    costo: { bloque: 'campoPorcentaje', descripcion: 'Actualiza el costo de compra y recalcula el precio con la ganancia y los factores vigentes.' },
    ganancia: { bloque: 'campoGanancia', descripcion: 'Fija el mismo multiplicador sobre el costo neto para todo el conjunto y recalcula.' },
    redondeo: { bloque: 'campoMultiplo', descripcion: 'Lleva el precio de venta al múltiplo más cercano, sin tocar el costo ni la ganancia.' },
    recalculo: { bloque: 'avisoRecalculo', descripcion: 'Recalcula el precio desde el costo vigente y la parametrización, sin cambiar nada más.' },
};

const BLOQUES = ['campoPorcentaje', 'campoGanancia', 'campoMultiplo', 'avisoRecalculo'];

// El color de cada resultado; el texto viene del servidor (ningún estado sólo con color).
const INSIGNIAS = {
    aplicado: 'text-bg-primary',
    sin_costo: 'text-bg-light border text-body-secondary',
    sin_cambio: 'text-bg-light border text-body-secondary',
    manual: 'text-bg-secondary',
};

// Muestra el campo de la operación elegida y apaga los otros, para que no viajen en la URL.
function refrescarOperacion(formulario) {
    const elegida = OPERACIONES[formulario.operacion.value] ?? OPERACIONES.precio;

    BLOQUES.forEach((id) => {
        const bloque = document.getElementById(id);
        if (!bloque) return;

        const corresponde = id === elegida.bloque;
        bloque.classList.toggle('d-none', !corresponde);

        const campo = bloque.querySelector('input');
        if (campo) {
            campo.disabled = !corresponde;
            campo.required = corresponde;
        }
    });

    const explicacion = document.getElementById('explicacionOperacion');
    if (explicacion) explicacion.textContent = elegida.descripcion;
}

// «Todo el catálogo» apaga los filtros: un recorte puesto con «todos» se rechaza, y con razón.
function refrescarAlcance() {
    const todos = document.getElementById('alcanceTodos').checked;
    const filtros = document.getElementById('bloqueFiltrosRecorte');

    filtros?.classList.toggle('d-none', todos);
    document.getElementById('avisoCatalogoCompleto')?.classList.toggle('d-none', !todos);

    filtros?.querySelectorAll('input, select').forEach((campo) => {
        campo.disabled = todos;
        campo.tomselect?.[todos ? 'disable' : 'enable']();
    });
}

/** El ajuste que pide la URL, como cuerpo de la API: sin la página ni la marca de previsualizar. */
function ajusteDeLaUrl() {
    const consulta = new URLSearchParams(window.location.search);
    const cuerpo = {};

    consulta.forEach((valor, clave) => {
        if (['previsualizar', 'pagina', 'incluir_manuales', 'ids[]'].includes(clave) || valor === '') return;
        cuerpo[clave] = valor;
    });

    // El oculto manda 0 y la casilla 1: vale el último, como en PHP.
    cuerpo.incluir_manuales = consulta.getAll('incluir_manuales').pop() === '1';

    const ids = consulta.getAll('ids[]');
    if (ids.length > 0) cuerpo.ids = ids.map(Number);

    return cuerpo;
}

function llenarProyeccion(fila, dato) {
    rellenar(fila, dato.id, {
        codigo: dato.codigo,
        nombre: dato.nombre,
        precio_contado: pesos(dato.precio_contado),
        nuevo_contado: pesos(dato.nuevo_contado),
        variacion: dato.variacion === null ? '—' : `${Number(dato.variacion) > 0 ? '+' : ''}${numero(dato.variacion, 2)} %`,
        resultado: dato.bajo_costo ? `${dato.resultado_texto} · bajo costo` : dato.resultado_texto,
    });

    // Lo que no viaja no se pinta: sin `precio.ver_costo` la celda ni existe en la plantilla.
    if ('costo' in dato) {
        fila.querySelector('[data-campo="costo"]').textContent = pesos(dato.costo_nuevo ?? dato.costo);
        fila.querySelector('[data-costo-nuevo]').hidden = dato.costo_nuevo === null;
    }

    const insignia = fila.querySelector('[data-campo="resultado"]');
    insignia.className = `badge ${dato.bajo_costo ? 'text-bg-warning' : INSIGNIAS[dato.resultado] ?? 'text-bg-light border'}`;
    if (dato.bajo_costo) fila.classList.add('table-warning');
}

// Los conteos del conjunto entero (no de la página) y los avisos que dependen de ellos.
function mostrarConteos(raiz, sobre) {
    raiz.querySelector('[data-conteos]').hidden = sobre === null;
    raiz.querySelector('[data-campo="criterio_texto"]').textContent = sobre?.criterio_texto ?? '';
    if (sobre === null) return;

    const { conteos } = sobre;
    raiz.querySelectorAll('[data-conteo]').forEach((lugar) => {
        lugar.textContent = String(conteos[lugar.dataset.conteo] ?? 0);
    });

    raiz.querySelector('[data-aviso-bajo-costo]').hidden = !(conteos.bajo_costo > 0);
    raiz.querySelector('[data-aviso-manuales]').hidden = !(conteos.manuales_afuera > 0);

    const confirmar = raiz.querySelector('[data-confirmar-ajuste]');
    if (confirmar) confirmar.hidden = !(conteos.alcanzados > 0);
}

/** La pantalla del ajuste: el formulario, sus selectores y, si la URL lo pide, la vista previa. */
export function iniciarAjusteMasivo() {
    const raiz = document.querySelector('[data-pantalla="ajuste-masivo"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-formulario-ajuste]');

    formulario.operacion.addEventListener('change', () => refrescarOperacion(formulario));
    formulario.querySelectorAll('input[name="alcance"]').forEach((radio) => radio.addEventListener('change', refrescarAlcance));
    refrescarOperacion(formulario);
    refrescarAlcance();

    // Cada selector pide su lista; si «todos» está elegido ya quedó apagado, y el buscador nace apagado.
    formulario.querySelectorAll('select[data-opciones]').forEach(cargarFiltro);

    if (new URLSearchParams(window.location.search).get('previsualizar') !== '1') return;

    const vista = raiz.querySelector('[data-vista-previa]');
    vista.hidden = false;

    iniciarListado(vista, {
        ruta: '/precios/ajustes/vista-previa',
        llenar: llenarProyeccion,
        formulario,
        alCargar: (sobre) => mostrarConteos(vista, sobre),
    });

    vista.querySelector('[data-aplicar-ajuste]')?.addEventListener('click', async (evento) => {
        const boton = evento.currentTarget;
        const alcanzados = vista.querySelector('[data-conteo="alcanzados"]').textContent;

        if (!window.confirm(`Se van a cambiar los precios de ${alcanzados} producto(s). Queda registrado y se puede deshacer. ¿Aplicar?`)) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.post('/precios/ajustes', ajusteDeLaUrl(), { formulario });
            completadoAlVolver(respuesta.mensaje, raiz.dataset.lotes);
        } catch {
            // El módulo central ya mostró el 409 o los errores por campo.
            liberar();
        }
    });
}

// La tarjeta del último ajuste: la acción de la pantalla, arriba y sola.
function mostrarReversible(raiz, lote) {
    const tarjeta = raiz.querySelector('[data-reversible]');
    tarjeta.hidden = lote === null;
    if (lote === null) return;

    rellenar(tarjeta, lote.id, {
        operacion_texto: lote.operacion_texto,
        parametro_texto: lote.parametro_texto,
        resumen: `${lote.criterio_texto} · ${lote.alcanzados} producto(s) · ${fechaHora(lote.fecha)} · ${lote.usuario ?? ''}`,
    });
    tarjeta.querySelector('[data-campo="parametro_texto"]').hidden = lote.parametro_texto === null;

    const boton = tarjeta.querySelector('[data-deshacer]');
    if (boton) {
        boton.dataset.ruta = `/precios/lotes/${lote.id}/deshacer`;
        boton.dataset.confirmar = `Se van a devolver los precios de ${lote.alcanzados} producto(s) a los que tenían antes del ajuste. Queda registrado como un ajuste nuevo. ¿Deshacer?`;
        boton.setAttribute('aria-label', `Deshacer el ajuste del ${fechaHora(lote.fecha)}`);
    }
}

function llenarLote(fila, lote) {
    let estado = 'Aplicado';
    let clase = 'text-bg-light border text-body-secondary';

    if (lote.operacion === 'deshacer') {
        estado = `Deshizo el ajuste del ${lote.revierte_a ? fechaHora(lote.revierte_a.fecha) : 'anterior'}`;
        clase = 'text-bg-secondary';
    } else if (lote.deshecho_el) {
        estado = `Deshecho el ${fechaHora(lote.deshecho_el)}`;
        clase = 'text-bg-secondary';
    } else if (lote.reversible) {
        estado = 'Se puede deshacer';
        clase = 'text-bg-dark';
    }

    rellenar(fila, lote.id, {
        fecha: fechaHora(lote.fecha),
        operacion_texto: lote.operacion_texto,
        parametro_texto: lote.parametro_texto,
        criterio_texto: lote.criterio_texto,
        alcanzados: String(lote.alcanzados),
        usuario: lote.usuario,
        estado,
        omitidos: `${lote.omitidos} sin devolver`,
    });

    fila.classList.toggle('table-light', lote.operacion === 'deshacer');
    fila.querySelector('[data-campo="parametro_texto"]').hidden = lote.parametro_texto === null;
    fila.querySelector('[data-campo="estado"]').className = `badge ${clase}`;
    fila.querySelector('[data-campo="omitidos"]').hidden = !(lote.operacion === 'deshacer' && lote.omitidos > 0);
}

/** El historial de ajustes, con el botón de deshacer en el único lote que el servidor dice que se puede. */
export function iniciarHistorialDeAjustes() {
    const raiz = document.querySelector('[data-pantalla="lotes"]');
    if (!raiz) return;

    const listado = iniciarListado(raiz, {
        ruta: '/precios/lotes',
        llenar: llenarLote,
        alCargar: (sobre) => mostrarReversible(raiz, sobre?.reversible ?? null),
    });

    raiz.querySelector('[data-deshacer]')?.addEventListener('click', async (evento) => {
        const boton = evento.currentTarget;
        if (!window.confirm(boton.dataset.confirmar)) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.post(boton.dataset.ruta, {});
            completado(respuesta.mensaje);
            await listado.recargar();
        } catch {
            // El módulo central ya mostró el 409.
        } finally {
            liberar();
        }
    });
}
