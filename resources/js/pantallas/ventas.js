// Las ventas, por la API (tramo H de la Etapa 3): el listado y la ficha. Los filtros siguen siendo un GET
// a la misma pantalla; lo que se pide a la API es la consulta de la URL tal cual.

import { api, ErrorDeApi } from '../api/cliente';
import { cargando, completado } from '../api/estados';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { fechaHora, numero, pesos } from './formato';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';

const dia = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });

// La clase del badge de cada estado (la de `Venta::estadoClase()`); el texto viene en `estado_texto`.
const CLASE_DEL_ESTADO = {
    presupuesto: 'text-bg-light border',
    pendiente_pago: 'text-bg-warning',
    pagada: 'text-bg-dark',
    en_preparacion: 'text-bg-dark',
    despachada: 'text-bg-dark',
    lista_retiro: 'text-bg-dark',
    entregada: 'text-bg-success',
    entregada_parcial: 'text-bg-warning',
    devuelta_parcial: 'text-bg-warning',
    devuelta: 'text-bg-warning',
    cancelada: 'text-bg-secondary',
};

// Las clases que el badge trae del shell se guardan la primera vez: repintar no las pierde.
function pintarEstado(badge, venta) {
    badge.dataset.claseBase ??= badge.className;
    badge.className = `${badge.dataset.claseBase} ${CLASE_DEL_ESTADO[venta.estado] ?? 'text-bg-light border'}`;
    badge.textContent = venta.estado_texto;
}

/** Muestra u oculta cada `[data-si="clave"]` según su condición. */
function mostrarSi(raiz, condiciones) {
    Object.entries(condiciones).forEach(([clave, vale]) => {
        raiz.querySelectorAll(`[data-si="${clave}"]`).forEach((lugar) => {
            lugar.hidden = !vale;
        });
    });
}

/** Lo que el módulo central ya mostró se calla; un error de programación, no. */
function callarErrorDeApi(error) {
    if (!(error instanceof ErrorDeApi)) throw error;
}

// ------------------------------------------------------------------
// El listado
// ------------------------------------------------------------------

function llenarVenta(fila, venta) {
    rellenar(fila, venta.id, {
        numero: venta.numero,
        fecha: dia.format(new Date(venta.fecha)),
        cliente_texto: venta.cliente_texto,
        vendedor: venta.vendedor ?? '—',
        canal_texto: venta.canal_texto,
        productos: String(venta.productos ?? ''),
        total: pesos(venta.total),
    });

    pintarEstado(fila.querySelector('[data-campo="estado_texto"]'), venta);
    fila.querySelector('[data-canal]').dataset.canal = venta.canal;
    fila.querySelector('[data-sin-stock]').hidden = !venta.sin_stock;
    fila.querySelector('[data-ver]').setAttribute('aria-label', `Ver la venta ${venta.numero}`);

    const editar = fila.querySelector('[data-editar]');
    if (editar) {
        editar.hidden = !venta.editable;
        editar.setAttribute('aria-label', `Editar la venta ${venta.numero}`);
    }
}

// Los vendedores del filtro: quienes cargaron ventas, con su permiso de módulo (HL-125).
async function cargarVendedores(select) {
    try {
        const vendedores = await todasLasPaginas('/ventas/vendedores');

        vendedores.forEach((vendedor) => {
            const opcion = new Option(vendedor.nombre, String(vendedor.id));
            opcion.selected = String(vendedor.id) === select.dataset.seleccionada;
            select.append(opcion);
        });
    } catch (error) {
        callarErrorDeApi(error);
    } finally {
        iniciarSelectBuscable(select);
    }
}

// Los chips del cliente y del vendedor: el nombre lo devuelve el servidor en `criterios`.
function nombrarChips(sobre) {
    if (!sobre) return;

    [['cliente_id', 'cliente'], ['vendedor_id', 'vendedor']].forEach(([parametro, clave]) => {
        const chip = document.querySelector(`[data-nombre-filtro="${parametro}"]`);
        if (chip && sobre.criterios?.[clave]) chip.textContent = sobre.criterios[clave];
    });
}

/** El listado de ventas, con el filtro de vendedor pedido a la API. */
export function iniciarListadoDeVentas() {
    const raiz = document.querySelector('[data-pantalla="ventas"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtros-ventas]');
    cargarVendedores(formulario.vendedor_id);

    iniciarListado(raiz, {
        ruta: '/ventas',
        llenar: llenarVenta,
        filtros: ['q', 'estado', 'cliente_id', 'vendedor_id', 'canal', 'pago', 'desde', 'hasta'],
        alCargar: nombrarChips,
        formulario,
    });
}

// ------------------------------------------------------------------
// La ficha
// ------------------------------------------------------------------

function filas(tbody, plantilla, datos, llenar) {
    tbody.replaceChildren(...datos.map((dato) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);
        llenar(fila, dato);

        return fila;
    }));
}

function llenarLinea(fila, linea, columnas) {
    const faltante = linea.faltante;

    rellenar(fila, linea.id, {
        descripcion: linea.descripcion,
        detalle: `${linea.producto.codigo ?? ''} · IVA ${numero(linea.alicuota_iva, 2)} %`,
        cantidad: String(linea.cantidad),
        faltante: faltante
            ? (faltante.disponible > 0 ? `Hay ${faltante.disponible} disponible(s)` : 'Sin stock disponible')
            : '',
        cantidad_entregada: String(linea.cantidad_entregada),
        falta_entregar: `faltan ${linea.pendiente_de_entregar}`,
        cantidad_devuelta: String(linea.cantidad_devuelta),
        precio_unitario: pesos(linea.precio_unitario),
        neto: pesos(linea.neto),
        total: pesos(linea.total),
    });

    // El IVA y los impuestos tal como se cobraron en el renglón.
    const celda = fila.querySelector('[data-impuestos]');
    if (linea.impuestos.length === 0) {
        celda.textContent = pesos(linea.iva);
    } else {
        celda.replaceChildren(...linea.impuestos.map((impuesto) => {
            const renglon = document.createElement('div');
            const nombre = document.createElement('span');
            nombre.className = 'text-body-secondary small';
            nombre.textContent = impuesto.nombre;
            renglon.append(nombre, ` ${pesos(impuesto.importe)}`);

            return renglon;
        }));
    }

    mostrarSi(fila, {
        faltante: Boolean(faltante),
        hay_entregas: columnas.entregas,
        hay_devoluciones: columnas.devoluciones,
        falta_entregar: linea.pendiente_de_entregar > 0,
        devuelta: linea.cantidad_devuelta > 0,
        sin_devolver: linea.cantidad_devuelta === 0,
    });
}

function llenarPago(fila, pago) {
    const monto = Number(pago.monto);

    rellenar(fila, pago.id, {
        fecha: fechaHora(pago.fecha),
        metodo_texto: pago.metodo_texto,
        registro: pago.registro ?? '—',
        monto: pago.es_devolucion ? `− ${pesos(Math.abs(monto))}` : pesos(monto),
    });

    fila.querySelector('[data-campo="monto"]').classList.toggle('text-danger', pago.es_devolucion);
    mostrarSi(fila, { es_devolucion: pago.es_devolucion });
}

function pintarFicha(raiz, venta) {
    const ficha = raiz.querySelector('[data-ficha]');
    const cliente = venta.cliente;
    const columnas = {
        entregas: venta.entregas.length > 0,
        devoluciones: venta.lineas.some((linea) => linea.cantidad_devuelta > 0),
    };

    document.title = `Venta ${venta.numero}`;

    rellenar(ficha, venta.id, {
        numero: venta.numero,
        cliente_texto: venta.cliente_texto,
        total: pesos(venta.importes.total),
        documento: venta.estado === 'presupuesto' ? 'el presupuesto' : 'el comprobante',
        razon_social: cliente?.razon_social,
        tipo_doc_texto: cliente?.tipo_doc_texto,
        nro_doc: cliente?.nro_doc,
        condicion_iva_texto: cliente?.condicion_iva_texto,
        telefono: cliente?.telefono,
        email: cliente?.email,
        emitida: `${fechaHora(venta.fecha)}${venta.vendedor ? ` · ${venta.vendedor}` : ''}`,
        modificada: fechaHora(venta.modificada),
        productos: String(venta.productos),
        subtotal: pesos(venta.importes.subtotal),
        descuento: pesos(venta.importes.descuento),
        descuento_porcentaje: numero(venta.importes.descuento_porcentaje, 2),
        observaciones: venta.observaciones,
        pagado: pesos(venta.importes.pagado),
        entregar_texto: columnas.entregas ? 'Entregar lo que falta' : 'Entregar todo',
        cancelar_texto: venta.es_online ? 'Cancelar el pedido' : 'Cancelar el presupuesto',
    });

    ficha.querySelectorAll('[data-campo="estado_texto"]').forEach((lugar) => {
        if (lugar.classList.contains('badge')) pintarEstado(lugar, venta);
        else lugar.textContent = venta.estado_texto;
    });

    ficha.querySelectorAll('[data-enlace-cliente]').forEach((enlace) => {
        enlace.dataset.plantilla ??= enlace.getAttribute('href');
        if (cliente) enlace.href = enlace.dataset.plantilla.replaceAll('__CLIENTE__', cliente.id);
    });

    // Las columnas de entregadas y devueltas: el pie ocupa lo que ocupe la tabla antes del total.
    ficha.querySelectorAll('[data-antes-del-total]').forEach((celda) => {
        celda.colSpan = 5 + Number(columnas.entregas) + Number(columnas.devoluciones);
    });

    mostrarSi(ficha, {
        sin_stock: venta.sin_stock,
        presupuesto: venta.estado === 'presupuesto',
        cancelada: venta.estado === 'cancelada',
        cliente: Boolean(cliente),
        sin_cliente: !cliente,
        nro_doc: Boolean(cliente?.nro_doc),
        telefono: Boolean(cliente?.telefono),
        email: Boolean(cliente?.email),
        modificada: Boolean(venta.modificada),
        descuento: Number(venta.importes.descuento) > 0,
        hay_entregas: columnas.entregas,
        hay_devoluciones: columnas.devoluciones,
        transferencia: venta.medio_pago === 'transferencia',
        sin_comprobantes: venta.comprobantes_transferencia.length === 0,
        con_comprobantes: venta.comprobantes_transferencia.length > 0,
        observaciones: Boolean(venta.observaciones),
        entregada_sin_comprobante: venta.entregada_sin_comprobante,
        hay_pagos: venta.pagos.length > 0,
    });

    filas(ficha.querySelector('[data-lineas]'), ficha.querySelector('template[data-plantilla-linea]'),
        venta.lineas, (fila, linea) => llenarLinea(fila, linea, columnas));

    filas(ficha.querySelector('[data-comprobantes]'), ficha.querySelector('template[data-plantilla-comprobante]'),
        venta.comprobantes_transferencia, (fila, comprobante) => {
            rellenar(fila, comprobante.id, { nombre_original: comprobante.nombre_original, fecha: fechaHora(comprobante.fecha) });
            const abrir = fila.querySelector('[data-abrir-comprobante]');
            if (abrir) abrir.dataset.comprobante = String(comprobante.id);
        });

    filas(ficha.querySelector('[data-entregas]'), ficha.querySelector('template[data-plantilla-entrega]'),
        venta.entregas, (fila, entrega) => {
            rellenar(fila, entrega.id, {
                numero: entrega.numero,
                fecha: fechaHora(entrega.fecha),
                quien: entrega.quien,
                unidades: String(entrega.unidades),
            });
            const descargar = fila.querySelector('[data-descargar-entrega]');
            if (descargar) {
                descargar.dataset.entrega = String(entrega.id);
                descargar.setAttribute('aria-label', `Descargar el comprobante de entrega ${entrega.numero}`);
            }
        });

    filas(ficha.querySelector('[data-pagos]'), ficha.querySelector('template[data-plantilla-pago]'), venta.pagos, llenarPago);

    // Lo que el estado admite lo dice la API; el `@can` del shell ya sacó lo que el permiso no deja.
    ficha.querySelectorAll('[data-accion]').forEach((boton) => {
        boton.hidden = !venta.acciones[boton.dataset.accion];
    });

    const salidas = ['cobrar', 'entregar', 'entregar_parte', 'devolver', 'cancelar'].some((accion) => venta.acciones[accion]);
    const sinSalidas = ficha.querySelector('[data-sin-salidas]');
    if (sinSalidas) {
        sinSalidas.hidden = salidas;
        ficha.querySelector('[data-salidas]').hidden = !salidas;
    }

    ficha.hidden = false;
}

// Cada transición: su endpoint y la confirmación que pide antes de mandarla.
const TRANSICIONES = {
    recotizar: {
        ruta: 'recotizar',
        confirmar: () => 'Esto trae los precios de hoy a todas las líneas del presupuesto. Después te vamos a decir qué cambió. ¿Seguimos?',
    },
    entregar: {
        ruta: 'entregas',
        confirmar: (venta) => `¿Entregar lo pendiente de la venta ${venta.numero}? No mueve stock: eso ya pasó al cobrar.`,
    },
    cancelar: {
        ruta: 'cancelar',
        confirmar: (venta) => (venta.es_online
            ? 'El pedido va a quedar cancelado y no se puede volver atrás. Las unidades que tiene reservadas vuelven al disponible y otro cliente las puede comprar. No se borra: queda registrado. ¿Seguimos?'
            : 'El presupuesto va a quedar cancelado y no se puede volver atrás. No se borra: queda registrado con sus líneas. ¿Seguimos?'),
    },
};

/** Pide un enlace firmado y lo abre (D-22): en la misma pestaña un PDF, en otra un comprobante. */
async function abrirEnlace(boton, ruta, nuevaPestana = false) {
    const liberar = cargando(boton);

    try {
        const { datos } = await api.get(ruta);
        if (nuevaPestana) window.open(datos.url, '_blank', 'noopener');
        else window.location.assign(datos.url);
    } catch (error) {
        callarErrorDeApi(error);
    } finally {
        liberar();
    }
}

/** La ficha: la pide a la API, ofrece lo que su estado admite y, tras cada acción, se vuelve a pintar. */
export function iniciarFichaDeVenta() {
    const raiz = document.querySelector('[data-pantalla="venta"]');
    if (!raiz) return;

    const ruta = `/ventas/${raiz.dataset.venta}`;
    let venta = null;

    const pintar = (datos) => {
        venta = datos;
        pintarFicha(raiz, venta);
    };

    api.get(ruta).then(({ datos }) => {
        pintar(datos);
    }).catch(callarErrorDeApi).finally(() => {
        raiz.querySelector('[data-cargando]').hidden = true;
        raiz.removeAttribute('aria-busy');
    });

    raiz.addEventListener('click', async (evento) => {
        if (!venta) return;

        const entrega = evento.target.closest('[data-descargar-entrega]');
        if (entrega) {
            abrirEnlace(entrega, `${ruta}/entregas/${entrega.dataset.entrega}/pdf`);

            return;
        }

        const comprobante = evento.target.closest('[data-abrir-comprobante]');
        if (comprobante) {
            abrirEnlace(comprobante, `${ruta}/comprobantes-transferencia/${comprobante.dataset.comprobante}`, true);

            return;
        }

        const boton = evento.target.closest('button[data-accion]');
        if (!boton) return;

        const accion = boton.dataset.accion;

        if (accion === 'imprimir') {
            abrirEnlace(boton, `${ruta}/pdf`);

            return;
        }

        const transicion = TRANSICIONES[accion];
        if (!transicion) return;
        if (transicion.confirmar && !window.confirm(transicion.confirmar(venta))) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.post(`${ruta}/${transicion.ruta}`, {});
            liberar();
            pintar(respuesta.datos);
            completado(respuesta.mensaje);
        } catch (error) {
            // El módulo central ya mostró el 409 con el motivo.
            liberar();
            callarErrorDeApi(error);
        }
    });
}

