// Las pantallas de precios, por la API (tramo F): la configuración (la política, los impuestos y los
// factores), los formularios de impuesto y de factor, y el simulador. El shell trae la estructura con un
// @can por acción; los datos, el envío y los avisos pasan por el módulo central.

import { api } from '../api/cliente';
import { cargando, completado, completadoAlVolver, limpiarErrores } from '../api/estados';
import { numero, pesos } from './formato';
import { rellenar, todasLasPaginas } from './listado';

// Una fila que ocupa toda la tabla, con un texto: cargando, vacío o sin lista.
function mensajeEnTabla(cuerpo, texto) {
    const celda = document.createElement('td');
    celda.colSpan = Number(cuerpo.dataset.columnas || 1);
    celda.className = 'text-center text-body-secondary py-4';
    celda.textContent = texto;

    const fila = document.createElement('tr');
    fila.append(celda);
    cuerpo.replaceChildren(fila);
}

// Un badge de estado: con texto, no sólo con color.
function badgeDeEstado(lugar, activo) {
    lugar.textContent = activo ? 'Activo' : 'Inactivo';
    lugar.classList.toggle('text-bg-dark', activo);
    lugar.classList.toggle('text-bg-secondary', !activo);
}

function filaDeImpuesto(plantilla, impuesto) {
    const fila = plantilla.content.firstElementChild.cloneNode(true);
    const porProducto = impuesto.alcance === 'producto';

    rellenar(fila, impuesto.id, {
        nombre: impuesto.nombre,
        alicuota: numero(impuesto.alicuota, 2),
        alcance: porProducto ? 'Por producto' : 'Todo el catálogo',
        comprobante: impuesto.comprobante_texto,
    });

    const alcance = fila.querySelector('[data-campo="alcance"]');
    alcance.classList.add(porProducto ? 'text-bg-dark' : 'text-bg-secondary');
    badgeDeEstado(fila.querySelector('[data-campo="estado"]'), impuesto.activo);

    // `productos` sólo viaja en las alícuotas de IVA: un impuesto general no lo usa ningún producto.
    const usados = impuesto.productos;
    const sinUso = fila.querySelector('[data-sin-uso]');
    const productos = fila.querySelector('[data-campo="productos"]');
    if (usados !== undefined) {
        sinUso.hidden = true;
        productos.hidden = false;
        productos.textContent = String(usados);
        if (usados === 0) productos.removeAttribute('href');
    }

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar el impuesto ${impuesto.nombre}`);

    const eliminar = fila.querySelector('[data-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar el impuesto ${impuesto.nombre}`);
        eliminar.dataset.confirmar = usados > 0
            ? `«${impuesto.nombre}» es la alícuota de ${usados} producto(s). No se eliminará: se desactivará. ¿Continuar?`
            : `¿Eliminar el impuesto «${impuesto.nombre}»?`;
    }

    return fila;
}

function filaDeFactor(plantilla, factor) {
    const fila = plantilla.content.firstElementChild.cloneNode(true);
    const porcentaje = Number(factor.porcentaje);

    // La operación escrita: es lo que vuelve visible que un recupero divide.
    const operacion = factor.modo === 'recupero'
        ? `÷ ${numero(1 - porcentaje / 100, 4)}`
        : `× ${numero(1 + porcentaje / 100, 4)}`;

    rellenar(fila, factor.id, {
        nombre: factor.nombre,
        modo: factor.modo_texto,
        porcentaje: numero(factor.porcentaje),
        operacion,
    });

    badgeDeEstado(fila.querySelector('[data-campo="estado"]'), factor.activo);
    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar el factor ${factor.nombre}`);

    const eliminar = fila.querySelector('[data-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar el factor ${factor.nombre}`);
        eliminar.dataset.confirmar = `¿Eliminar el factor «${factor.nombre}»? Los precios que ya lo tienen adentro no cambian hasta que los recalcules.`;
    }

    return fila;
}

/**
 * Una tabla de configuración: son pocas filas, así que se traen todas las páginas (la API igual pagina,
 * regla 15) y no hay pie. Dos listas en la misma pantalla no se pisan el `?pagina=` de la URL.
 */
function tablaDeConfiguracion(raiz, { ruta, cuerpo, plantilla, armar }) {
    const tbody = raiz.querySelector(cuerpo);
    const molde = raiz.querySelector(plantilla);

    async function recargar() {
        tbody.setAttribute('aria-busy', 'true');
        mensajeEnTabla(tbody, 'Cargando…');

        try {
            const filas = await todasLasPaginas(ruta);

            if (filas.length === 0) mensajeEnTabla(tbody, tbody.dataset.vacio);
            else tbody.replaceChildren(...filas.map((dato) => armar(molde, dato)));
        } catch {
            // El aviso ya lo dio el módulo central.
            mensajeEnTabla(tbody, 'No se pudo cargar la lista.');
        } finally {
            tbody.removeAttribute('aria-busy');
        }
    }

    tbody.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('[data-eliminar]');
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
}

// Los números de la política en sus campos, con el formato de la pantalla (2,5 y no 2.500).
function mostrarPolitica(raiz, politica) {
    const formulario = raiz.querySelector('form[data-formulario-politica]');

    if (formulario) {
        formulario.ganancia_global.value = numero(politica.ganancia_global);
        formulario.recargo_lista.value = numero(politica.recargo_lista);
        formulario.redondeo_multiplo.value = numero(politica.redondeo_multiplo, 2);
        formulario.redondeo_modo.value = politica.redondeo_modo;

        // El badge del margen compara contra lo guardado: ver el bloque de la política en app.js.
        const badge = formulario.querySelector('#badgeMargen');
        badge.dataset.guardado = politica.ganancia_global;
        formulario.querySelector('#margenValor').textContent = numero(politica.margen_global, 2);
        formulario.ganancia_global.dispatchEvent(new Event('input'));
    }

    const lectura = raiz.querySelector('[data-politica-lectura]');
    if (lectura) {
        rellenar(lectura, null, {
            ganancia_global: numero(politica.ganancia_global),
            margen_global: numero(politica.margen_global, 2),
            recargo_lista: numero(politica.recargo_lista),
            redondeo_multiplo: numero(politica.redondeo_multiplo, 2),
            redondeo_modo_texto: politica.redondeo_modo_texto,
        });
    }
}

/** La configuración de precios: la política, y las tablas de impuestos y de factores. */
export function iniciarPantallaDePrecios() {
    const raiz = document.querySelector('[data-pantalla="precios"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-formulario-politica]');
    const enviar = formulario?.querySelector('[type="submit"]');
    const liberar = cargando(enviar);

    api.get('/precios/politica').then(({ datos }) => {
        mostrarPolitica(raiz, datos);
        liberar();
    }).catch(() => {
        // El módulo central ya lo mostró; sin la política no hay qué guardar.
    });

    formulario?.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberarEnvio = cargando(enviar);

        try {
            const respuesta = await api.patch('/precios/politica', {
                ganancia_global: formulario.ganancia_global.value,
                recargo_lista: formulario.recargo_lista.value,
                redondeo_multiplo: formulario.redondeo_multiplo.value,
                redondeo_modo: formulario.redondeo_modo.value,
            }, { formulario });

            completado(respuesta.mensaje);
            mostrarPolitica(raiz, respuesta.datos);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
        } finally {
            liberarEnvio();
        }
    });

    tablaDeConfiguracion(raiz, { ruta: '/impuestos', cuerpo: 'tbody[data-impuestos]', plantilla: 'template[data-impuesto-fila]', armar: filaDeImpuesto });
    tablaDeConfiguracion(raiz, { ruta: '/factores-costo', cuerpo: 'tbody[data-factores]', plantilla: 'template[data-factor-fila]', armar: filaDeFactor });
}

/**
 * Un formulario de alta y edición: en la edición trae la ficha; al guardar vuelve a la configuración con
 * el aviso. `campos(formulario)` arma el cuerpo y `cargar(formulario, dato)` llena los campos.
 */
function formularioDeConfiguracion(formulario, { id, ruta, campos, cargar }) {
    const enviar = formulario.querySelector('[type="submit"]');

    if (id) {
        const liberar = cargando(enviar);

        api.get(`${ruta}/${id}`).then(({ datos }) => {
            cargar(formulario, datos);
            liberar();
        }).catch(() => {
            // El módulo central ya lo mostró; sin el dato no hay qué guardar.
        });
    }

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(enviar);

        try {
            const respuesta = id
                ? await api.patch(`${ruta}/${id}`, campos(formulario), { formulario })
                : await api.post(ruta, campos(formulario), { formulario });

            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}

/** El formulario de impuesto. */
export function iniciarFormularioDeImpuesto() {
    const formulario = document.querySelector('form[data-formulario-impuesto]');
    if (!formulario) return;

    formularioDeConfiguracion(formulario, {
        id: formulario.dataset.impuesto,
        ruta: '/impuestos',
        // El alcance deshabilitado viaja igual: el servidor rechaza cualquier otro que el que tiene.
        campos: (f) => ({
            nombre: f.nombre.value,
            alicuota: f.alicuota.value,
            alcance: f.alcance.value,
            comprobante: f.comprobante.value,
            orden: f.orden.value,
            activo: f.activo.checked,
        }),
        cargar: (f, impuesto) => {
            f.nombre.value = impuesto.nombre;
            f.alicuota.value = numero(impuesto.alicuota, 2);
            f.alcance.value = impuesto.alcance;
            f.comprobante.value = impuesto.comprobante;
            f.orden.value = String(impuesto.orden);
            f.activo.checked = impuesto.activo;

            const enUso = (impuesto.productos ?? 0) > 0;
            f.querySelector('[data-ayuda-en-uso]').hidden = !enUso;
            f.querySelector('[data-ayuda-sin-uso]').hidden = enUso;
            document.querySelector('[data-titulo-impuesto]').textContent = `Editar «${impuesto.nombre}»`;
        },
    });
}

/** El formulario de factor de costo. */
export function iniciarFormularioDeFactor() {
    const formulario = document.querySelector('form[data-formulario-factor]');
    if (!formulario) return;

    formularioDeConfiguracion(formulario, {
        id: formulario.dataset.factor,
        ruta: '/factores-costo',
        campos: (f) => ({
            nombre: f.nombre.value,
            modo: f.modo.value,
            porcentaje: f.porcentaje.value,
            orden: f.orden.value,
            activo: f.activo.checked,
        }),
        cargar: (f, factor) => {
            f.nombre.value = factor.nombre;
            f.modo.value = factor.modo;
            f.porcentaje.value = numero(factor.porcentaje);
            f.orden.value = String(factor.orden);
            f.activo.checked = factor.activo;
            document.querySelector('[data-titulo-factor]').textContent = `Editar «${factor.nombre}»`;
        },
    });
}

// Una fila «concepto | importe» de las tablas del simulador.
function filaDeDesglose(concepto, detalle, importe, { total = false, tenue = false } = {}) {
    const fila = document.createElement('tr');
    if (total) fila.className = 'border-top';

    const encabezado = document.createElement('th');
    encabezado.scope = 'row';
    encabezado.className = total ? 'h6 mb-0' : 'fw-normal';
    encabezado.textContent = concepto;

    if (detalle) {
        const aparte = document.createElement('span');
        aparte.className = 'text-body-secondary';
        aparte.textContent = ` ${detalle}`;
        encabezado.append(aparte);
    }

    const celda = document.createElement('td');
    celda.className = `text-end${total ? ' h6 mb-0' : ''}${tenue ? ' text-body-secondary' : ''}`;
    celda.textContent = importe;

    fila.append(encabezado, celda);

    return fila;
}

// El desglose del comprobante de un precio (contado o lista): neto, cada impuesto y el final.
function pintarEtapa(cuerpo, etapa) {
    cuerpo.replaceChildren(
        filaDeDesglose('Neto', null, pesos(etapa.neto)),
        ...etapa.impuestos.map((impuesto) => filaDeDesglose(impuesto.nombre, `${numero(impuesto.alicuota, 2)} %`, pesos(impuesto.importe))),
        filaDeDesglose('Precio final', null, pesos(etapa.final), { total: true }),
    );
}

function pintarSimulacion(raiz, calculo) {
    const factores = calculo.factores.length === 0
        ? [(() => {
            const fila = document.createElement('tr');
            const celda = document.createElement('td');
            celda.colSpan = 2;
            celda.className = 'text-body-secondary small';
            celda.textContent = 'No hay factores de costo activos.';
            fila.append(celda);

            return fila;
        })()]
        : calculo.factores.map((factor) => {
            const porcentaje = Number(factor.porcentaje);
            const operacion = factor.modo === 'recupero'
                ? `÷ ${numero(1 - porcentaje / 100, 4)}`
                : `× ${numero(1 + porcentaje / 100, 4)}`;

            return filaDeDesglose(factor.nombre, operacion, `+ ${pesos(factor.importe)}`, { tenue: true });
        });

    const margen = filaDeDesglose('Margen sobre la venta', null, `${numero(calculo.margen, 2)} %`);
    const neto = filaDeDesglose('Neto de venta', null, pesos(calculo.contado.neto), { total: true });
    neto.querySelector('th').className = '';
    neto.querySelector('td').className = 'text-end fw-semibold';

    raiz.querySelector('[data-formacion-neto]').replaceChildren(
        filaDeDesglose('Costo neto', null, pesos(calculo.costo_neto)),
        filaDeDesglose('Ganancia', `× ${numero(calculo.ganancia)}`, pesos(Number(calculo.costo_neto) * Number(calculo.ganancia)), { tenue: true }),
        ...factores,
        neto,
        margen,
    );

    pintarEtapa(raiz.querySelector('[data-desglose="contado"]'), calculo.contado);
    pintarEtapa(raiz.querySelector('[data-desglose="lista"]'), calculo.lista);
}

/**
 * El simulador. El formulario sigue siendo un GET a la misma pantalla (el resultado queda en la URL y se
 * comparte); al abrirse, la pantalla lee la URL y le pide el cálculo a la API.
 */
export function iniciarSimulador() {
    const raiz = document.querySelector('[data-pantalla="simulador"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-formulario-simulador]');
    const enviar = formulario.querySelector('[type="submit"]');
    const consulta = new URLSearchParams(window.location.search);
    const liberar = cargando(enviar);

    formulario.costo.value = consulta.get('costo') ?? '';
    formulario.ganancia.value = consulta.get('ganancia') ?? '';

    const opciones = todasLasPaginas('/impuestos').then((impuestos) => {
        const alicuotas = impuestos.filter((impuesto) => impuesto.alcance === 'producto' && impuesto.activo);
        const elegida = consulta.get('alicuota_iva') ?? alicuotas[0]?.alicuota ?? '';

        formulario.alicuota_iva.replaceChildren(...alicuotas.map((impuesto) => {
            const opcion = document.createElement('option');
            opcion.value = impuesto.alicuota;
            opcion.textContent = impuesto.nombre;
            opcion.selected = impuesto.alicuota === elegida;

            return opcion;
        }));
    });

    const politica = api.get('/precios/politica').then(({ datos }) => {
        formulario.ganancia.placeholder = numero(datos.ganancia_global);
        raiz.querySelector('[data-ganancia-global]').textContent = ` (× ${numero(datos.ganancia_global)})`;
        rellenar(raiz.querySelector('[data-simulador-resultado]'), null, {
            recargo_lista: numero(datos.recargo_lista),
            redondeo_multiplo: numero(datos.redondeo_multiplo, 2),
            redondeo_modo_texto: datos.redondeo_modo_texto,
        });
    });

    Promise.all([opciones, politica]).then(async () => {
        if (!consulta.get('costo')) return;

        raiz.querySelector('[data-limpiar]').hidden = false;

        const parametros = new URLSearchParams({ costo: formulario.costo.value, alicuota_iva: formulario.alicuota_iva.value });
        if (formulario.ganancia.value.trim() !== '') parametros.set('ganancia', formulario.ganancia.value);

        const { datos } = await api.get(`/precios/simulacion?${parametros}`, { formulario });
        pintarSimulacion(raiz, datos.calculo);
        raiz.querySelector('[data-simulador-vacio]').hidden = true;
        raiz.querySelector('[data-simulador-resultado]').hidden = false;
    }).catch(() => {
        // El módulo central ya marcó los campos o dio el aviso.
    }).finally(liberar);
}
