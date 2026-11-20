// Las pantallas de marcas, por la API (tramo D): el listado y el formulario de alta y edición.
// El shell trae la estructura; los datos, el envío y los avisos pasan por el módulo central.

import { api } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarGestor } from '../componentes/gestor-imagenes';
import { iniciarListado, rellenar } from './listado';

// Una fila de la tabla: el texto va siempre por textContent, nunca como HTML.
function llenarFila(fila, marca) {
    rellenar(fila, marca.id, {
        nombre: marca.nombre,
        slug: marca.slug,
        productos: String(marca.productos),
        'productos-movil': `${marca.productos} producto(s)`,
        estado: marca.activo ? 'Activa' : 'Inactiva',
    });

    const estado = fila.querySelector('[data-campo="estado"]');
    estado.classList.toggle('text-bg-dark', marca.activo);
    estado.classList.toggle('text-bg-secondary', !marca.activo);

    const logo = fila.querySelector('[data-logo]');
    const sinLogo = fila.querySelector('[data-sin-logo]');
    if (marca.logo) logo.src = marca.logo;
    logo.hidden = !marca.logo;
    sinLogo.hidden = Boolean(marca.logo);

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${marca.nombre}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar la marca ${marca.nombre}`);
        eliminar.dataset.confirmar = marca.productos > 0
            ? `«${marca.nombre}» tiene ${marca.productos} producto(s). No se eliminará: se desactivará. ¿Continuar?`
            : `¿Eliminar la marca «${marca.nombre}»?`;
    }
}

/** El listado de marcas. */
export function iniciarListadoDeMarcas() {
    const raiz = document.querySelector('[data-pantalla="marcas"]');
    if (!raiz) return;

    iniciarListado(raiz, { ruta: '/marcas', llenar: llenarFila, filtros: ['q', 'estado'] });
}

/** El formulario: en la edición trae la marca por la API; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDeMarca() {
    const formulario = document.querySelector('form[data-formulario-marca]');
    if (!formulario) return;

    const id = formulario.dataset.marca;
    const enviar = formulario.querySelector('[type="submit"]');
    const gestor = formulario.querySelector('.js-gestor-imagenes');

    if (id) {
        const liberar = cargando(enviar);

        api.get(`/marcas/${id}`).then(({ datos }) => {
            formulario.nombre.value = datos.nombre;
            formulario.activo.checked = datos.activo;
            document.querySelector('[data-titulo-marca]').textContent = `Editar «${datos.nombre}»`;
            if (gestor) iniciarGestor(gestor, datos.logo ? [{ ruta: datos.logo, url: datos.logo }] : []);
            liberar();
        }).catch(() => {
            // El módulo central ya lo mostró; sin la marca no hay qué guardar.
        });
    } else if (gestor) {
        iniciarGestor(gestor, []);
    }

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        // La API no adivina un checkbox ausente: `activo` viaja siempre.
        const datos = new FormData(formulario);
        datos.delete('_token');
        datos.set('activo', formulario.activo.checked ? '1' : '0');

        // Con archivo, PATCH no sirve: PHP no lee los archivos de un PATCH multipart (HL-110).
        if (id) datos.set('_method', 'PATCH');

        const liberar = cargando(enviar);

        try {
            const respuesta = await api.post(id ? `/marcas/${id}` : '/marcas', datos, { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}
