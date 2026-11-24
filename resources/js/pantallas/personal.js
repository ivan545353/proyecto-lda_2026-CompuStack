// Las pantallas del personal, por la API (tramo I): el listado, el formulario con el enlace de
// restablecimiento, y el cambio de rol. El shell trae la estructura; los datos, el envío y los avisos
// pasan por el módulo central. Lo que la propia cuenta no puede hacer se esconde acá como ayuda: quien
// lo decide es el servidor.

import { api, ErrorDeApi } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';

// Los roles del personal en un selector, con el elegido marcado. Devuelve el rol elegido, si lo hay.
async function cargarRoles(select, elegido = '') {
    try {
        const roles = await todasLasPaginas('/personal/roles');

        roles.forEach((rol) => {
            const opcion = new Option(rol.texto, String(rol.id));
            opcion.selected = String(rol.id) === elegido;
            select.append(opcion);
        });

        return roles.find((rol) => String(rol.id) === elegido) ?? null;
    } catch (error) {
        // El módulo central ya avisó; el selector queda con su opción vacía.
        if (!(error instanceof ErrorDeApi)) throw error;

        return null;
    } finally {
        iniciarSelectBuscable(select);
    }
}

// Una fila de la tabla: el texto va siempre por textContent, nunca como HTML.
function llenarFila(fila, persona) {
    rellenar(fila, persona.id, {
        nombre: persona.nombre_completo,
        email: persona.email,
        rol: persona.rol.nombre,
        acceso: persona.acceso_texto,
        situacion: persona.situacion_texto,
    });

    const acceso = fila.querySelector('[data-campo="acceso"]');
    acceso.classList.toggle('text-bg-dark', persona.activo);
    acceso.classList.toggle('text-bg-secondary', !persona.activo);

    fila.querySelector('[data-campo="situacion"]').classList.toggle('text-body-secondary', persona.situacion_texto !== 'En actividad');
    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${persona.nombre_completo}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (!eliminar) return;

    if (persona.es_la_propia) {
        eliminar.remove();

        return;
    }

    eliminar.setAttribute('aria-label', `Eliminar la cuenta de ${persona.nombre_completo}`);
    eliminar.dataset.confirmar = `¿Eliminar la cuenta de ${persona.nombre_completo}? Si tiene operaciones registradas no se va a borrar: se le quita el acceso, para no perder el historial.`;
}

/** El listado del personal, con las opciones del filtro de rol. */
export function iniciarListadoDePersonal() {
    const raiz = document.querySelector('[data-pantalla="personal"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtros-personal]');
    const selector = formulario.rol_id;

    cargarRoles(selector, selector.dataset.seleccionada).then((elegido) => {
        const chip = raiz.querySelector('[data-nombre-filtro="rol_id"]');
        if (chip && elegido) chip.textContent = elegido.texto;
    });

    iniciarListado(raiz, {
        ruta: '/personal',
        llenar: llenarFila,
        filtros: ['q', 'rol_id', 'estado', 'situacion'],
        formulario,
    });
}

// Lo que se envía: el rol y la contraseña sólo en el alta (en la edición el servidor los prohíbe, C-3).
function datosDelFormulario(formulario, esEdicion) {
    const campo = (nombre) => formulario.elements.namedItem(nombre)?.value ?? '';

    const datos = {
        nombre: campo('nombre'),
        apellido: campo('apellido'),
        email: campo('email'),
        activo: formulario.activo.checked,
        empleado: {
            legajo: campo('empleado.legajo'),
            dni: campo('empleado.dni'),
            telefono: campo('empleado.telefono'),
            fecha_ingreso: campo('empleado.fecha_ingreso'),
        },
    };

    if (esEdicion) {
        datos.empleado.fecha_baja = campo('empleado.fecha_baja');
    } else {
        datos.rol_id = campo('rol_id');
        datos.password = campo('password');
        datos.password_confirmation = campo('password_confirmation');
    }

    return datos;
}

// El bloque «Acceso a la cuenta»: el camino propio o el enlace para otra persona.
function iniciarAccesoACuenta(bloque, persona) {
    bloque.querySelector('[data-cuenta-propia]').hidden = !persona.es_la_propia;

    const ajena = bloque.querySelector('[data-cuenta-ajena]');
    ajena.hidden = persona.es_la_propia;
    if (persona.es_la_propia) return;

    ajena.querySelector('[data-campo="nombre-pila"]').textContent = persona.nombre;

    const enlace = ajena.querySelector('[data-enlace-restablecimiento]');
    const generar = ajena.querySelector('[data-accion="generar-enlace"]');
    const copiar = ajena.querySelector('[data-accion="copiar-enlace"]');

    generar.addEventListener('click', async () => {
        const pregunta = `Se va a generar un enlace para que ${persona.nombre} configure su contraseña. La actual va a seguir sirviendo hasta que la cambie. ¿Continuar?`;
        if (!window.confirm(pregunta)) return;

        const liberar = cargando(generar);

        try {
            const respuesta = await api.post(`/personal/${persona.id}/restablecer-contrasena`, {});
            enlace.querySelector('[data-campo="enlace-nombre"]').textContent = respuesta.datos.nombre;
            enlace.querySelector('[data-campo="enlace-url"]').value = respuesta.datos.enlace_restablecimiento;
            enlace.hidden = false;
            enlace.scrollIntoView({ block: 'center' });
        } catch {
            // El módulo central ya lo mostró (409 si la cuenta no tiene acceso).
        }

        liberar();
    });

    // Mejora sobre el campo de sólo lectura, que igual se puede seleccionar y copiar a mano.
    copiar.addEventListener('click', async () => {
        const campo = enlace.querySelector('[data-campo="enlace-url"]');
        campo.select();

        try {
            await navigator.clipboard.writeText(campo.value);
            copiar.replaceChildren(Object.assign(document.createElement('i'), { className: 'bi bi-check-lg' }), ' Copiado');
        } catch {
            document.execCommand('copy');
        }
    });
}

/** El formulario: en la edición trae la ficha por la API; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDePersonal() {
    const formulario = document.querySelector('form[data-formulario-personal]');
    if (!formulario) return;

    const id = formulario.dataset.usuario;
    const enviar = formulario.querySelector('[type="submit"]');

    if (!id) {
        cargarRoles(formulario.rol_id);
    } else {
        const liberar = cargando(enviar);

        api.get(`/personal/${id}`).then(({ datos: persona }) => {
            const empleado = persona.empleado ?? {};

            formulario.nombre.value = persona.nombre;
            formulario.apellido.value = persona.apellido;
            formulario.email.value = persona.email;
            formulario.activo.checked = persona.activo;
            formulario.elements.namedItem('empleado.legajo').value = empleado.legajo ?? '';
            formulario.elements.namedItem('empleado.dni').value = empleado.dni ?? '';
            formulario.elements.namedItem('empleado.telefono').value = empleado.telefono ?? '';
            formulario.elements.namedItem('empleado.fecha_ingreso').value = empleado.fecha_ingreso ?? '';
            formulario.elements.namedItem('empleado.fecha_baja').value = empleado.fecha_baja ?? '';
            formulario.querySelector('[data-campo="rol"]').textContent = persona.rol.nombre;
            document.querySelector('[data-titulo-personal]').textContent = `Editar a ${persona.nombre_completo}`;

            // La propia cuenta: no se quita el acceso ni se cambia el rol a sí misma.
            formulario.activo.disabled = persona.es_la_propia;
            formulario.querySelector('[data-acceso-propio]').hidden = !persona.es_la_propia;
            const cambiarRol = formulario.querySelector('[data-cambiar-rol]');
            if (cambiarRol) cambiarRol.hidden = persona.es_la_propia;

            const acceso = document.querySelector('[data-acceso-cuenta]');
            if (acceso) iniciarAccesoACuenta(acceso, persona);

            liberar();
        }).catch((error) => {
            // El módulo central ya lo mostró; sin la persona no hay qué guardar.
            if (!(error instanceof ErrorDeApi)) throw error;
        });
    }

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(enviar);

        try {
            const respuesta = id
                ? await api.patch(`/personal/${id}`, datosDelFormulario(formulario, true), { formulario })
                : await api.post('/personal', datosDelFormulario(formulario, false), { formulario });

            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}

/** El cambio de rol: la ficha trae el rol actual, lo que hoy puede y los roles asignables. */
export function iniciarCambioDeRol() {
    const formulario = document.querySelector('form[data-formulario-rol]');
    if (!formulario) return;

    const id = formulario.dataset.usuario;
    const enviar = formulario.querySelector('[type="submit"]');
    const liberar = cargando(enviar);

    api.get(`/personal/${id}`).then(({ datos: persona }) => {
        document.querySelectorAll('[data-campo="nombre"]').forEach((lugar) => {
            lugar.textContent = persona.nombre_completo;
        });
        formulario.querySelector('[data-campo="rol-actual"]').textContent = persona.rol.nombre;

        const descripcion = formulario.querySelector('[data-campo="rol-descripcion"]');
        descripcion.textContent = persona.rol.descripcion ?? '';
        descripcion.hidden = !persona.rol.descripcion;

        (persona.roles_asignables ?? []).forEach((rol) => {
            const opcion = new Option(rol.actual ? `${rol.nombre} (actual)` : rol.nombre, String(rol.id));
            opcion.disabled = rol.actual;
            formulario.rol_id.append(opcion);
        });

        const permisos = document.querySelector('[data-permisos-actuales]');
        const lista = persona.rol.permisos ?? [];
        permisos.replaceChildren(...(lista.length ? lista : [permisos.dataset.vacio]).map((texto) => {
            const linea = document.createElement(lista.length ? 'div' : 'p');
            linea.className = lista.length ? 'small text-body-secondary' : 'small text-body-secondary mb-0';
            linea.textContent = texto;

            return linea;
        }));

        liberar();
    }).catch((error) => {
        // El módulo central ya lo mostró; sin la ficha no hay roles que ofrecer.
        if (!(error instanceof ErrorDeApi)) throw error;
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberarEnvio = cargando(enviar);

        try {
            const respuesta = await api.put(`/personal/${id}/rol`, { rol_id: formulario.rol_id.value }, { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó el campo o dio el aviso.
            liberarEnvio();
        }
    });
}
