// Las órdenes de compra, por la API (tramo G de la Etapa 3): el listado y la ficha. Los filtros siguen
// siendo un GET a la misma pantalla; lo que se pide a la API es la consulta de la URL tal cual.

import { api } from '../api/cliente';
import { cargando, completado } from '../api/estados';
import { iniciarSeleccion } from '../componentes/seleccion';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { fechaHora, pesos } from './formato';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';

const dia = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });

// La clase del badge de cada estado; el texto viene del backend en `estado_texto`.
const CLASE_DEL_ESTADO = {
    borrador: 'text-bg-light border',
    aprobada: 'text-bg-dark',
    enviada: 'text-bg-dark',
    recibida_parcial: 'text-bg-warning',
    recibida: 'text-bg-success',
    cancelada: 'text-bg-secondary',
};

// Las clases que el badge trae del shell se guardan la primera vez: repintar no las pierde.
function pintarEstado(badge, orden) {
    badge.dataset.claseBase ??= badge.className;
    badge.className = `${badge.dataset.claseBase} ${CLASE_DEL_ESTADO[orden.estado] ?? 'text-bg-light border'}`;
    badge.textContent = orden.estado_texto;
}

/** Muestra u oculta cada `[data-si="clave"]` según su condición. */
function mostrarSi(raiz, condiciones) {
    Object.entries(condiciones).forEach(([clave, vale]) => {
        raiz.querySelectorAll(`[data-si="${clave}"]`).forEach((lugar) => {
            lugar.hidden = !vale;
        });
    });
}

// ------------------------------------------------------------------
// El listado
// ------------------------------------------------------------------

function llenarOrden(fila, orden) {
    rellenar(fila, orden.id, {
        numero: orden.numero,
        fecha: dia.format(new Date(orden.fecha)),
        proveedor: orden.proveedor.razon_social,
        productos: String(orden.productos ?? ''),
        total_estimado: pesos(orden.total_estimado),
    });

    pintarEstado(fila.querySelector('[data-campo="estado_texto"]'), orden);
    fila.querySelector('[data-generada]').hidden = !orden.generada_por_el_sistema;
    fila.querySelector('[data-ver]').setAttribute('aria-label', `Ver la orden ${orden.numero}`);

    const editar = fila.querySelector('[data-editar]');
    if (editar) {
        editar.hidden = !orden.editable;
        editar.setAttribute('aria-label', `Editar la orden ${orden.numero}`);
    }
}

// Los proveedores del filtro: todos, también los inactivos (una orden vieja puede ser de uno).
async function cargarProveedores(select) {
    try {
        const proveedores = await todasLasPaginas('/compras/proveedores');

        proveedores.forEach((proveedor) => {
            const opcion = new Option(
                proveedor.activo ? proveedor.razon_social : `${proveedor.razon_social} (inactivo)`,
                String(proveedor.id),
            );
            opcion.selected = String(proveedor.id) === select.dataset.seleccionada;
            select.append(opcion);
        });

        const elegido = proveedores.find((proveedor) => String(proveedor.id) === select.dataset.seleccionada);
        const chip = document.querySelector('[data-nombre-filtro="proveedor_id"]');
        if (chip && elegido) chip.textContent = elegido.razon_social;
    } catch {
        // El módulo central ya avisó; el filtro queda en «Todos».
    } finally {
        iniciarSelectBuscable(select);
    }
}

/** El listado de órdenes, con el filtro de proveedor pedido a la API. */
export function iniciarListadoDeCompras() {
    const raiz = document.querySelector('[data-pantalla="compras"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtros-compras]');
    cargarProveedores(formulario.proveedor_id);

    iniciarListado(raiz, {
        ruta: '/compras',
        llenar: llenarOrden,
        filtros: ['q', 'estado', 'origen', 'proveedor_id', 'desde', 'hasta'],
        formulario,
    });
}

// ------------------------------------------------------------------
// Los sugeridos
// ------------------------------------------------------------------

const cuantos = (n) => `${n} ${n === 1 ? 'producto' : 'productos'}`;

function llenarSugerido(fila, producto) {
    rellenar(fila, producto.id, {
        nombre: producto.nombre,
        codigo: producto.codigo,
        disponible: String(producto.disponible),
        stock_minimo: String(producto.stock_minimo),
        cantidad_reposicion: String(producto.cantidad_reposicion),
        costo_ultimo: producto.costo_ultimo ? pesos(producto.costo_ultimo) : '',
    });

    const casilla = fila.querySelector('[data-casilla]');
    casilla.value = String(producto.id);
    casilla.setAttribute('aria-label', `Marcar ${producto.codigo} — ${producto.nombre} para el pedido`);
    fila.dataset.producto = String(producto.id);

    mostrarSi(fila, {
        agotado: producto.disponible <= 0,
        con_stock: producto.disponible > 0,
        sin_cantidad: producto.cantidad_reposicion < 1,
        // Sin precio.ver_costo la clave no viene y la celda tampoco está en el shell.
        sin_costo: producto.costo_ultimo === null,
        con_costo: Boolean(producto.costo_ultimo),
    });
}

/** Qué hay que reponer: cada fila debajo de la cabecera de su proveedor, con su botón de pedirle. */
export function iniciarSugeridos() {
    const raiz = document.querySelector('[data-pantalla="sugeridos"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('#formSugeridos');
    const tbody = raiz.querySelector('[data-listado-filas]');
    const plantillaGrupo = raiz.querySelector('template[data-plantilla-grupo]');

    const agrupar = (sobre) => {
        const hay = Boolean(sobre?.datos.length);

        raiz.querySelector('[data-totales]').hidden = !hay;
        raiz.querySelector('[data-pedir-marcados]').hidden = !hay;
        if (sobre) rellenar(raiz.querySelector('[data-totales]'), '', {
            total: String(sobre.total),
            estimado_pagina: 'estimado_pagina' in sobre ? pesos(sobre.estimado_pagina) : '',
        });

        const aviso = raiz.querySelector('[data-sin-proveedor]');
        aviso.hidden = !sobre?.sin_proveedor;
        aviso.dataset.sinProveedor = String(sobre?.sin_proveedor ?? 0);
        if (sobre?.sin_proveedor) {
            aviso.querySelector('[data-campo="sin_proveedor_texto"]').textContent = sobre.sin_proveedor === 1
                ? '1 producto no se puede pedir porque no tiene proveedor.'
                : `${sobre.sin_proveedor} productos no se pueden pedir porque no tienen proveedor.`;
        }

        if (!hay) return;

        const filas = new Map([...tbody.querySelectorAll('tr[data-producto]')].map((fila) => [fila.dataset.producto, fila]));

        tbody.replaceChildren(...sobre.grupos.flatMap((grupo) => {
            const cabecera = plantillaGrupo.content.firstElementChild.cloneNode(true);
            const n = grupo.productos.length;

            rellenar(cabecera, '', {
                proveedor: grupo.proveedor.razon_social,
                resumen: 'estimado' in grupo ? `${cuantos(n)} · estimado ${pesos(grupo.estimado)}` : cuantos(n),
                pedir: `Pedirle estos ${n}`,
            });

            const enlace = cabecera.querySelector('[data-pedir-grupo]');
            const consulta = new URLSearchParams(grupo.productos.map((id) => ['productos[]', String(id)]));
            enlace.href = `${raiz.dataset.armadorUrl}?${consulta}`;
            enlace.setAttribute('aria-label', `Armar el pedido con los ${n} productos de ${grupo.proveedor.razon_social}`);

            return [cabecera, ...grupo.productos.map((id) => filas.get(String(id))).filter(Boolean)];
        }));

        iniciarSeleccion(formulario);
    };

    iniciarListado(raiz, { ruta: '/compras/sugeridos', llenar: llenarSugerido, alCargar: agrupar });
}

// ------------------------------------------------------------------
// La ficha
// ------------------------------------------------------------------

function llenarLinea(fila, linea) {
    rellenar(fila, linea.id, {
        nombre: linea.producto.nombre,
        codigo: linea.producto.codigo,
        codigo_proveedor: linea.codigo_proveedor,
        cantidad_pedida: String(linea.cantidad_pedida),
        cantidad_recibida: String(linea.cantidad_recibida),
        cantidad_pendiente: String(linea.cantidad_pendiente),
        costo_unitario: pesos(linea.costo_unitario),
        subtotal: pesos(linea.subtotal),
    });

    mostrarSi(fila, {
        codigo_proveedor: Boolean(linea.codigo_proveedor),
        pendiente: linea.cantidad_pendiente > 0,
        completa: linea.cantidad_pendiente === 0,
    });
}

function llenarRecepcion(fila, movimiento) {
    rellenar(fila, movimiento.id, {
        cuando: fechaHora(movimiento.fecha),
        remito_texto: movimiento.remito_texto,
        producto: movimiento.producto.nombre,
        codigo: movimiento.producto.codigo,
        cantidad: `+${movimiento.cantidad}`,
        stock_anterior: String(movimiento.stock_anterior),
        stock_resultante: String(movimiento.stock_resultante),
        stock_leido: `De ${movimiento.stock_anterior} a ${movimiento.stock_resultante} unidades`,
        registrado_por: movimiento.registrado_por ?? '—',
    });

    mostrarSi(fila, { sin_remito: movimiento.remito_texto === null });
}

function filas(tbody, plantilla, datos, llenar) {
    tbody.replaceChildren(...datos.map((dato) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);
        llenar(fila, dato);

        return fila;
    }));
}

function pintarFicha(raiz, orden) {
    const ficha = raiz.querySelector('[data-ficha]');
    const proveedor = orden.proveedor;

    document.title = `Orden ${orden.numero}`;

    rellenar(ficha, orden.id, {
        numero: orden.numero,
        proveedor: proveedor.razon_social,
        total_estimado: pesos(orden.total_estimado),
        cuit: proveedor.cuit_formateado,
        contacto: proveedor.contacto,
        canal_pedido_texto: proveedor.canal_pedido_texto,
        plazo: String(proveedor.plazo_entrega_dias),
        creada: `${fechaHora(orden.fecha)} · ${orden.creada_por ?? 'por el sistema'}`,
        aprobada: orden.fecha_aprobacion
            ? `${fechaHora(orden.fecha_aprobacion)}${orden.aprobada_por ? ` · ${orden.aprobada_por}` : ''}`
            : 'todavía no',
        enviada: orden.fecha_envio ? fechaHora(orden.fecha_envio) : 'todavía no',
        email: proveedor.email,
        telefono: proveedor.telefono,
        portal_url: proveedor.portal_url,
        observaciones: orden.observaciones,
    });

    ficha.querySelectorAll('[data-campo="estado_texto"]').forEach((lugar) => {
        if (lugar.classList.contains('badge')) pintarEstado(lugar, orden);
        else lugar.textContent = orden.estado_texto;
    });
    ficha.querySelector('[data-generada]').hidden = !orden.generada_por_el_sistema;

    const portal = ficha.querySelector('[data-enlace-portal]');
    if (proveedor.portal_url) portal.href = proveedor.portal_url;

    // «Qué sigue» cuando lo próximo es hacerle llegar el pedido: lo dice `acciones.enviar`, no la pantalla.
    const queSigue = ficha.querySelector('[data-que-sigue]');
    queSigue.hidden = !orden.acciones.enviar;
    const canal = ['email', 'portal_externo'].includes(proveedor.canal_pedido) ? proveedor.canal_pedido : 'otro';
    queSigue.querySelectorAll('[data-canal]').forEach((parrafo) => {
        parrafo.hidden = parrafo.dataset.canal !== canal;
    });

    mostrarSi(ficha, {
        cuit: Boolean(proveedor.cuit_formateado),
        contacto: Boolean(proveedor.contacto),
        plazo: proveedor.plazo_entrega_dias > 0,
        email: Boolean(proveedor.email),
        telefono: Boolean(proveedor.telefono),
        portal_url: Boolean(proveedor.portal_url),
        observaciones: Boolean(orden.observaciones),
        recepciones: orden.recepciones.length > 0,
    });

    filas(ficha.querySelector('[data-lineas]'), ficha.querySelector('template[data-plantilla-linea]'), orden.lineas, llenarLinea);
    filas(ficha.querySelector('[data-recepciones]'), ficha.querySelector('template[data-plantilla-recepcion]'), orden.recepciones, llenarRecepcion);

    // Lo que el estado admite lo dice la API; el `@can` del shell ya sacó lo que el permiso no deja.
    ficha.querySelectorAll('[data-accion]').forEach((boton) => {
        boton.hidden = !orden.acciones[boton.dataset.accion];
    });

    const salidas = ['aprobar', 'enviar', 'recibir', 'cerrar', 'cancelar'].some((accion) => orden.acciones[accion]);
    ficha.querySelector('[data-sin-salidas]').hidden = salidas;
    ficha.querySelector('[data-salidas]').hidden = !salidas;

    ficha.hidden = false;
}

// Cada transición: su endpoint y la confirmación que pide antes de mandarla, si la pide.
const TRANSICIONES = {
    aprobar: {
        ruta: 'aprobar',
        confirmar: (orden) => `¿Aprobar la orden ${orden.numero} por ${pesos(orden.total_estimado)}? El monto queda comprometido y las líneas se congelan.`,
    },
    enviar: { ruta: 'enviada', confirmar: null },
    cerrar: {
        ruta: 'cerrar',
        confirmar: (orden) => `Quedan ${orden.unidades_pendientes} unidad(es) sin recibir. ¿Cerrar la orden con lo que llegó? Las líneas van a seguir mostrando el saldo.`,
    },
    cancelar: {
        ruta: 'cancelar',
        confirmar: (orden) => `¿Cancelar la orden ${orden.numero}? No se puede deshacer.`,
    },
};

/** La ficha: la pide a la API, ofrece lo que su estado admite y, tras cada acción, se vuelve a pintar. */
export function iniciarFichaDeCompra() {
    const raiz = document.querySelector('[data-pantalla="orden-compra"]');
    if (!raiz) return;

    const ruta = `/compras/${raiz.dataset.orden}`;
    let orden = null;

    const pintar = (datos) => {
        orden = datos;
        pintarFicha(raiz, orden);
    };

    api.get(ruta).then(({ datos }) => {
        pintar(datos);
    }).catch(() => {
        // El módulo central ya lo mostró; sin la orden no hay ficha que pintar.
    }).finally(() => {
        raiz.querySelector('[data-cargando]').hidden = true;
        raiz.removeAttribute('aria-busy');
    });

    raiz.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('button[data-accion]');
        if (!boton || !orden) return;

        const accion = boton.dataset.accion;

        if (accion === 'imprimir') {
            const liberar = cargando(boton);
            try {
                const { datos } = await api.get(`${ruta}/pdf`);
                window.location.assign(datos.url);
            } catch {
                // El módulo central ya lo mostró: una orden sin aprobar es 409.
            } finally {
                liberar();
            }

            return;
        }

        const transicion = TRANSICIONES[accion];
        if (!transicion) return;
        if (transicion.confirmar && !window.confirm(transicion.confirmar(orden))) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.post(`${ruta}/${transicion.ruta}`, {});
            liberar();
            pintar(respuesta.datos);
            completado(respuesta.mensaje);
        } catch {
            // El módulo central ya mostró el 409 con el motivo.
            liberar();
        }
    });
}
