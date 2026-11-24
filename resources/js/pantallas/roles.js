// Las pantallas de roles y permisos, por la API (tramo I): el listado y el formulario con las casillas por
// módulo. El shell trae la estructura y las plantillas; el catálogo de permisos, el rol y el envío pasan
// por el módulo central. La regla «cualquier acción implica ver» la vuelve a aplicar el servicio: acá es
// sólo para que la pantalla no ofrezca un rol que no sirve.

import { api, ErrorDeApi } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';

// Una fila de la tabla: el texto va siempre por textContent, nunca como HTML.
function llenarFila(fila, rol) {
    rellenar(fila, rol.id, {
        nombre: rol.nombre,
        descripcion: rol.descripcion ?? '',
        'conteos-movil': `${rol.cantidad_permisos} permisos · ${rol.cantidad_usuarios} usuarios`,
        ambito: rol.ambito_texto,
        permisos: String(rol.cantidad_permisos),
        usuarios: String(rol.cantidad_usuarios),
    });

    fila.querySelector('[data-campo="sistema"]').hidden = !rol.es_sistema;

    const ambito = fila.querySelector('[data-campo="ambito"]');
    ambito.classList.toggle('text-bg-dark', rol.ambito === 'gestion');
    ambito.classList.toggle('text-bg-info', rol.ambito !== 'gestion');

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${rol.nombre}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (!eliminar) return;

    if (!rol.se_puede_eliminar) {
        eliminar.remove();

        return;
    }

    eliminar.setAttribute('aria-label', `Eliminar ${rol.nombre}`);
    eliminar.dataset.confirmar = `¿Eliminar el rol ${rol.nombre}?`;
}

/** El listado de roles. */
export function iniciarListadoDeRoles() {
    const raiz = document.querySelector('[data-pantalla="roles"]');
    if (!raiz) return;

    iniciarListado(raiz, { ruta: '/roles', llenar: llenarFila });
}

// Las casillas por módulo, con el catálogo agrupado; marca las que el rol ya tiene.
function pintarPermisos(contenedor, permisos, marcados) {
    const plantillaModulo = document.querySelector('template[data-modulo-permisos]');
    const plantillaPermiso = document.querySelector('template[data-permiso]');
    const porModulo = new Map();

    permisos.forEach((permiso) => {
        if (!porModulo.has(permiso.modulo)) porModulo.set(permiso.modulo, []);
        porModulo.get(permiso.modulo).push(permiso);
    });

    contenedor.replaceChildren(...[...porModulo].map(([modulo, delModulo]) => {
        const tarjeta = plantillaModulo.content.firstElementChild.cloneNode(true);
        const todos = tarjeta.querySelector('.js-modulo');
        todos.id = `modulo-${modulo}`;
        todos.dataset.modulo = modulo;
        todos.setAttribute('aria-describedby', `ayuda-${modulo}`);
        tarjeta.querySelector('[data-campo="modulo"]').htmlFor = todos.id;
        tarjeta.querySelector('[data-campo="modulo"]').textContent = modulo;

        const ayuda = tarjeta.querySelector('[data-campo="ayuda-modulo"]');
        ayuda.id = `ayuda-${modulo}`;
        ayuda.textContent = `Marca o desmarca todos los permisos de ${modulo}`;

        tarjeta.querySelector('[data-lista-permisos]').replaceChildren(...delModulo.map((permiso) => {
            const fila = plantillaPermiso.content.firstElementChild.cloneNode(true);
            const casilla = fila.querySelector('.js-permiso');
            casilla.id = `permiso-${permiso.id}`;
            casilla.value = String(permiso.id);
            casilla.dataset.modulo = modulo;
            casilla.dataset.accion = permiso.clave.split('.').slice(1).join('.');
            casilla.checked = marcados.has(permiso.id);
            fila.querySelector('label').htmlFor = casilla.id;
            fila.querySelector('[data-campo="descripcion"]').textContent = permiso.descripcion;
            fila.querySelector('[data-campo="clave"]').textContent = permiso.clave;

            return fila;
        }));

        return tarjeta;
    }));
}

// Dependencia con «ver», casilla por módulo y contador (lo que antes vivía suelto en app.js).
function cablearPermisos(formulario) {
    const permisos = [...formulario.querySelectorAll('.js-permiso')];
    const contador = formulario.querySelector('[data-contador-permisos]');

    const delModulo = (modulo) => permisos.filter((c) => c.dataset.modulo === modulo);
    const verDe = (modulo) => delModulo(modulo).find((c) => c.dataset.accion === 'ver');

    const refrescar = () => {
        formulario.querySelectorAll('.js-modulo').forEach((casilla) => {
            const hijos = delModulo(casilla.dataset.modulo);
            const marcados = hijos.filter((c) => c.checked).length;

            casilla.checked = marcados === hijos.length;
            casilla.indeterminate = marcados > 0 && marcados < hijos.length;
        });

        contador.textContent = `${permisos.filter((c) => c.checked).length} de ${permisos.length} asignados`;
    };

    formulario.querySelectorAll('.js-modulo').forEach((casilla) => {
        casilla.addEventListener('change', () => {
            delModulo(casilla.dataset.modulo).forEach((c) => (c.checked = casilla.checked));
            refrescar();
        });
    });

    permisos.forEach((casilla) => {
        casilla.addEventListener('change', () => {
            const modulo = casilla.dataset.modulo;
            const ver = verDe(modulo);

            if (ver) {
                // Cualquier acción implica poder ver el módulo
                if (casilla.checked && casilla.dataset.accion !== 'ver') ver.checked = true;

                // Y al revés: quitar «ver» quita todo el módulo, porque el resto quedaría inaccesible
                if (!casilla.checked && casilla.dataset.accion === 'ver') {
                    delModulo(modulo).forEach((c) => (c.checked = false));
                }
            }

            refrescar();
        });
    });

    refrescar();
}

/** El formulario: trae el catálogo y, en la edición, el rol; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDeRol() {
    const formulario = document.querySelector('form[data-formulario-de-rol]');
    if (!formulario) return;

    const id = formulario.dataset.rol;
    const enviar = formulario.querySelector('[type="submit"]');
    const liberar = cargando(enviar);

    Promise.all([
        todasLasPaginas('/permisos'),
        id ? api.get(`/roles/${id}`).then(({ datos }) => datos) : Promise.resolve(null),
    ]).then(([permisos, rol]) => {
        if (rol) {
            formulario.nombre.value = rol.nombre;
            formulario.ambito.value = rol.ambito;
            formulario.descripcion.value = rol.descripcion ?? '';
            // Un rol de sistema no cambia de nombre ni de ámbito: deshabilitados, no viajan.
            formulario.nombre.disabled = rol.es_sistema;
            formulario.ambito.disabled = rol.es_sistema;
            formulario.querySelector('[data-rol-sistema]').hidden = !rol.es_sistema;
            document.querySelector('[data-titulo-rol]').textContent = `Editar «${rol.nombre}»`;
        }

        pintarPermisos(formulario.querySelector('[data-permisos]'), permisos, new Set(rol?.permisos ?? []));
        cablearPermisos(formulario);
        liberar();
    }).catch((error) => {
        // El módulo central ya lo mostró; sin el catálogo no hay qué guardar.
        if (!(error instanceof ErrorDeApi)) throw error;
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const datos = {
            descripcion: formulario.descripcion.value,
            permisos: [...formulario.querySelectorAll('.js-permiso:checked')].map((c) => Number(c.value)),
        };
        if (!formulario.nombre.disabled) datos.nombre = formulario.nombre.value;
        if (!formulario.ambito.disabled) datos.ambito = formulario.ambito.value;

        const liberarEnvio = cargando(enviar);

        try {
            const respuesta = id
                ? await api.patch(`/roles/${id}`, datos, { formulario })
                : await api.post('/roles', datos, { formulario });

            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso (409 si es el rol propio).
            liberarEnvio();
        }
    });
}
