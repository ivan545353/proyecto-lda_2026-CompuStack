// Las pantallas de clientes, por la API (tramo E): el listado, y el formulario con sus direcciones y
// el acceso a la tienda. El shell trae la estructura; los datos, el envío y los avisos pasan por el
// módulo central.

import { api } from '../api/cliente';
import { cargando, completado, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarListado, rellenar } from './listado';

// Una fila de la tabla: el texto va siempre por textContent, nunca como HTML.
function llenarFila(fila, cliente) {
    rellenar(fila, cliente.id, {
        razon_social: cliente.razon_social,
        documento: cliente.documento,
        'condicion-movil': cliente.condicion_iva_texto,
        condicion: cliente.condicion_iva_texto,
        comprobante: cliente.tipo_comprobante_texto,
        cuenta: cliente.con_cuenta ? 'Con cuenta' : 'De mostrador',
        direcciones: String(cliente.cantidad_direcciones ?? 0),
    });

    const cuenta = fila.querySelector('[data-campo="cuenta"]');
    cuenta.classList.toggle('text-bg-dark', cliente.con_cuenta);
    cuenta.classList.toggle('text-bg-secondary', !cliente.con_cuenta);

    const correo = fila.querySelector('[data-campo="cuenta-correo"]');
    correo.hidden = !(cliente.con_cuenta && cliente.cuenta_email);
    if (!correo.hidden) correo.textContent = cliente.cuenta_email;

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${cliente.razon_social}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar a ${cliente.razon_social}`);
        eliminar.dataset.confirmar = `¿Eliminar a «${cliente.razon_social}» y sus direcciones? Si tiene ventas, no se va a poder.`;
    }
}

/** El listado de clientes. */
export function iniciarListadoDeClientes() {
    const raiz = document.querySelector('[data-pantalla="clientes"]');
    if (!raiz) return;

    iniciarListado(raiz, { ruta: '/clientes', llenar: llenarFila, filtros: ['q', 'condicion_iva', 'cuenta'] });
}

// Las direcciones de la ficha, una fila por dirección con su plantilla.
function pintarDirecciones(contenedor, direcciones, alEliminar) {
    const lista = contenedor.querySelector('[data-direcciones-lista]');
    const plantilla = contenedor.querySelector('template[data-direccion-fila]');

    if (direcciones.length === 0) {
        const vacio = document.createElement('p');
        vacio.className = 'text-body-secondary small border-top pt-3 mb-0';
        vacio.textContent = 'Todavía no tiene direcciones cargadas. Hacen falta sólo si se le va a enviar mercadería.';
        lista.replaceChildren(vacio);

        return;
    }

    lista.replaceChildren(...direcciones.map((direccion) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        fila.querySelector('[data-campo="resumen"]').textContent = direccion.resumen;
        fila.querySelector('[data-campo="predeterminada"]').hidden = !direccion.es_predeterminada;

        const editar = fila.querySelector('[data-accion="editar-direccion"]');
        editar.href = editar.getAttribute('href').replaceAll('__DIR__', direccion.id);
        editar.setAttribute('aria-label', `Editar la dirección ${direccion.resumen}`);

        const eliminar = fila.querySelector('[data-direccion-eliminar]');
        eliminar.setAttribute('aria-label', `Eliminar la dirección ${direccion.resumen}`);
        eliminar.addEventListener('click', () => alEliminar(direccion, eliminar));

        return fila;
    }));
}

/** El formulario: en la edición trae la ficha por la API; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDeCliente() {
    const formulario = document.querySelector('form[data-formulario-cliente]');
    if (!formulario) return;

    const id = formulario.dataset.cliente;
    const enviar = formulario.querySelector('[type="submit"]');

    const acceso = document.querySelector('[data-acceso-tienda]');
    const enlace = document.querySelector('[data-enlace-tienda]');
    const direcciones = document.querySelector('[data-direcciones]');

    // Lo que depende de la ficha: la cuenta de la tienda, el acceso y las direcciones.
    function mostrarFicha(cliente) {
        const aviso = formulario.querySelector('[data-cuenta-tienda]');
        if (aviso) {
            aviso.hidden = !cliente.con_cuenta;
            if (cliente.con_cuenta) aviso.querySelector('[data-campo="cuenta-email"]').textContent = cliente.cuenta_email ?? '';
        }

        if (acceso) {
            acceso.hidden = cliente.con_cuenta;
            acceso.querySelector('[data-sin-correo]').hidden = Boolean(cliente.email);
            acceso.querySelector('[data-con-correo]').hidden = !cliente.email;
            acceso.querySelector('[data-campo="acceso-correo"]').textContent = cliente.email ?? '';
        }

        if (direcciones) {
            pintarDirecciones(direcciones, cliente.direcciones ?? [], (direccion, boton) => eliminarDireccion(cliente, direccion, boton));
        }
    }

    async function cargarFicha() {
        const { datos } = await api.get(`/clientes/${id}`);
        mostrarFicha(datos);

        return datos;
    }

    async function eliminarDireccion(cliente, direccion, boton) {
        const reemplazo = direccion.es_predeterminada && cliente.direcciones.length > 1;
        const pregunta = reemplazo
            ? `¿Eliminar «${direccion.resumen}»? Es la predeterminada: la más antigua de las que quedan va a pasar a serlo.`
            : `¿Eliminar «${direccion.resumen}»?`;

        if (!window.confirm(pregunta)) return;

        const liberar = cargando(boton);

        try {
            const respuesta = await api.delete(`/clientes/${id}/direcciones/${direccion.id}`);
            completado(respuesta?.mensaje);
            await cargarFicha();
        } catch {
            // El módulo central ya lo mostró.
            liberar();
        }
    }

    if (id) {
        const liberar = cargando(enviar);

        cargarFicha().then((cliente) => {
            formulario.razon_social.value = cliente.razon_social;
            formulario.condicion_iva.value = cliente.condicion_iva;
            formulario.tipo_doc.value = cliente.tipo_doc;
            formulario.nro_doc.value = cliente.nro_doc ?? '';
            formulario.email.value = cliente.email ?? '';
            formulario.telefono.value = cliente.telefono ?? '';
            document.querySelector('[data-titulo-cliente]').textContent = `Editar «${cliente.razon_social}»`;
            liberar();
        }).catch(() => {
            // El módulo central ya lo mostró; sin el cliente no hay qué guardar.
        });
    }

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const datos = {
            razon_social: formulario.razon_social.value,
            condicion_iva: formulario.condicion_iva.value,
            tipo_doc: formulario.tipo_doc.value,
            nro_doc: formulario.nro_doc.value,
            email: formulario.email.value,
            telefono: formulario.telefono.value,
        };

        const liberar = cargando(enviar);

        try {
            if (id) {
                const respuesta = await api.patch(`/clientes/${id}`, datos, { formulario });
                completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
            } else {
                // El alta sigue en la ficha: ahí se le agregan las direcciones.
                const respuesta = await api.post('/clientes', datos, { formulario });
                completadoAlVolver(respuesta.mensaje, formulario.dataset.editar.replace('__ID__', respuesta.datos.id));
            }
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });

    // El acceso a la tienda: el enlace viaja sólo en esta respuesta y se muestra una vez (D-26).
    const formularioAcceso = acceso?.querySelector('form[data-formulario-acceso]');
    formularioAcceso?.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formularioAcceso);

        const boton = formularioAcceso.querySelector('[type="submit"]');
        const liberar = cargando(boton);

        try {
            const respuesta = await api.post(`/clientes/${id}/acceso-tienda`, {
                nombre: formularioAcceso.nombre.value,
                apellido: formularioAcceso.apellido.value,
            }, { formulario: formularioAcceso });

            completado(respuesta.mensaje);
            enlace.querySelector('[data-campo="enlace-nombre"]').textContent = respuesta.datos.nombre;
            enlace.querySelector('[data-campo="enlace-url"]').value = respuesta.datos.enlace_restablecimiento;
            enlace.hidden = false;
            enlace.scrollIntoView({ block: 'center' });
            await cargarFicha();
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}
