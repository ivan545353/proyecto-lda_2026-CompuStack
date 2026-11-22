// Las pantallas de proveedores, por la API (tramo E): el listado y el formulario de alta y edición.
// El shell trae la estructura; los datos, el envío y los avisos pasan por el módulo central.

import { api } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarListado, rellenar } from './listado';

/**
 * Un número de la fila: enlace al catálogo o a las compras filtrados si hay cantidad y la fila trae
 * el enlace (lo trae sólo con el permiso de ese listado); número suelto si no; guion si es cero.
 * `texto(cantidad, grupo)` arma lo que se ve, porque en móvil el número lleva su sustantivo.
 */
function pintarCantidad(fila, clave, cantidad, texto, etiqueta = null) {
    fila.querySelectorAll(`[data-cantidad="${clave}"]`).forEach((grupo) => {
        const enlace = grupo.querySelector('a');
        const plano = grupo.querySelector('[data-plano]');
        const cero = grupo.querySelector('[data-cero]');

        if (grupo.hasAttribute('data-solo-con-cantidad')) grupo.hidden = cantidad === 0;
        if (cero) cero.hidden = cantidad !== 0;

        if (enlace) enlace.hidden = true;
        plano.hidden = true;

        if (cantidad === 0) return;

        const destino = enlace ?? plano;
        destino.textContent = texto(cantidad, grupo);
        destino.hidden = false;

        if (enlace && etiqueta && grupo.tagName === 'TD') enlace.setAttribute('aria-label', etiqueta);
    });
}

// Sólo se abre un portal con http(s): lo demás no es una dirección a la que mandar a nadie.
const esWeb = (direccion) => /^https?:\/\//i.test(direccion ?? '');

// Una fila de la tabla: el texto va siempre por textContent, nunca como HTML.
function llenarFila(fila, proveedor) {
    rellenar(fila, proveedor.id, {
        razon_social: proveedor.razon_social,
        detalle: `CUIT ${proveedor.cuit_formateado}${proveedor.contacto ? ` · ${proveedor.contacto}` : ''}`,
        canal: proveedor.canal_pedido_texto,
        plazo: proveedor.plazo_entrega_dias > 0 ? `~${proveedor.plazo_entrega_dias} día(s)` : 'No informado',
        estado: proveedor.activo ? 'Activo' : 'Inactivo',
    });

    fila.querySelector('[data-campo="plazo"]').classList.toggle('text-body-secondary', proveedor.plazo_entrega_dias <= 0);

    const estado = fila.querySelector('[data-campo="estado"]');
    estado.classList.toggle('text-bg-dark', proveedor.activo);
    estado.classList.toggle('text-bg-secondary', !proveedor.activo);

    const portal = fila.querySelector('[data-portal]');
    portal.hidden = !(proveedor.canal_pedido === 'portal_externo' && esWeb(proveedor.portal_url));
    if (!portal.hidden) {
        portal.href = proveedor.portal_url;
        portal.setAttribute('aria-label', `Abrir el portal de ${proveedor.razon_social} en una pestaña nueva`);
    }

    const correo = fila.querySelector('[data-campo="correo"]');
    correo.hidden = !(proveedor.canal_pedido === 'email' && proveedor.email);
    if (!correo.hidden) correo.textContent = proveedor.email;

    pintarCantidad(fila, 'productos', proveedor.productos,
        (n, grupo) => (grupo.tagName === 'TD' ? String(n) : `${n} producto(s)`),
        `Ver los ${proveedor.productos} productos que se le compran a ${proveedor.razon_social}`);
    pintarCantidad(fila, 'ordenes', proveedor.ordenes_compra,
        (n, grupo) => (grupo.tagName === 'TD' ? String(n) : `${n} orden(es)`),
        `Ver las ${proveedor.ordenes_compra} órdenes de compra de ${proveedor.razon_social}`);

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${proveedor.razon_social}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar el proveedor ${proveedor.razon_social}`);
        eliminar.dataset.confirmar = proveedor.productos + proveedor.ordenes_compra > 0
            ? `«${proveedor.razon_social}» tiene ${proveedor.productos} producto(s) y ${proveedor.ordenes_compra} orden(es) de compra. No se va a eliminar: se desactiva y su historial queda intacto. ¿Continuar?`
            : `¿Eliminar el proveedor «${proveedor.razon_social}»?`;
    }
}

/** El listado de proveedores. */
export function iniciarListadoDeProveedores() {
    const raiz = document.querySelector('[data-pantalla="proveedores"]');
    if (!raiz) return;

    iniciarListado(raiz, { ruta: '/proveedores', llenar: llenarFila, filtros: ['q', 'estado', 'canal'] });
}

/** El aviso de la cascada: el número a la vista, antes de pedirla. */
function textoDeLosProductos(cantidad) {
    const producto = cantidad === 1 ? 'producto activo' : 'productos activos';

    return `Este proveedor provee ${cantidad} ${producto}. Si lo desactivás, siguen como están: se pueden seguir vendiendo.`;
}

/** El formulario: en la edición trae el proveedor por la API; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDeProveedor() {
    const formulario = document.querySelector('form[data-formulario-proveedor]');
    if (!formulario) return;

    const id = formulario.dataset.proveedor;
    const enviar = formulario.querySelector('[type="submit"]');

    if (id) {
        const liberar = cargando(enviar);

        api.get(`/proveedores/${id}`).then(({ datos }) => {
            formulario.razon_social.value = datos.razon_social;
            formulario.cuit.value = datos.cuit_formateado;
            formulario.contacto.value = datos.contacto ?? '';
            formulario.email.value = datos.email ?? '';
            formulario.telefono.value = datos.telefono ?? '';
            formulario.canal_pedido.value = datos.canal_pedido;
            formulario.portal_url.value = datos.portal_url ?? '';
            formulario.plazo_entrega_dias.value = datos.plazo_entrega_dias;
            formulario.activo.checked = datos.activo;
            document.querySelector('[data-titulo-proveedor]').textContent = `Editar «${datos.razon_social}»`;

            // La dirección del portal sólo corresponde a su canal: lo resuelve app.js al cambiar el canal.
            formulario.canal_pedido.dispatchEvent(new Event('change'));

            const aviso = formulario.querySelector('[data-aviso-productos]');
            if (aviso && datos.activo && datos.productos_activos > 0) {
                aviso.querySelector('[data-productos-texto]').textContent = textoDeLosProductos(datos.productos_activos);
                aviso.hidden = false;
            }

            liberar();
        }).catch(() => {
            // El módulo central ya lo mostró; sin el proveedor no hay qué guardar.
        });
    }

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        // `activo` viaja siempre: la API no adivina una casilla ausente (M-31). La dirección del portal
        // deshabilitada (otro canal) va vacía, no se manda lo que quedó escrito.
        const datos = {
            razon_social: formulario.razon_social.value,
            cuit: formulario.cuit.value,
            contacto: formulario.contacto.value,
            email: formulario.email.value,
            telefono: formulario.telefono.value,
            canal_pedido: formulario.canal_pedido.value,
            portal_url: formulario.portal_url.disabled ? null : formulario.portal_url.value,
            plazo_entrega_dias: formulario.plazo_entrega_dias.value === '' ? null : Number(formulario.plazo_entrega_dias.value),
            activo: formulario.activo.checked,
            desactivar_productos: formulario.desactivar_productos?.checked ?? false,
        };

        const liberar = cargando(enviar);

        try {
            const respuesta = id
                ? await api.patch(`/proveedores/${id}`, datos, { formulario })
                : await api.post('/proveedores', datos, { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}
