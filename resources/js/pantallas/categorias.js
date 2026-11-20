// Las pantallas de categorías, por la API (tramo D): el listado con su navegación por el árbol y el
// formulario de alta y edición. El shell trae la estructura; los datos pasan por el módulo central.

import { api } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';
import { llenarOpcionesDeCategoria, porRuta } from './opciones-categoria';

// El grupo que se está mirando: el id de la URL, o null si es la raíz o todas.
function grupoDeLaUrl() {
    const padre = new URLSearchParams(window.location.search).get('parent_id');

    return /^\d+$/.test(padre ?? '') ? padre : null;
}

// La miga de pan, el botón de alta y los textos que nombran al grupo, con su ficha.
function pintarGrupo(raiz, categoria) {
    const miga = document.querySelector('[data-miga]');
    const lista = miga?.querySelector('ol');

    if (lista) {
        [...categoria.ancestros, null].forEach((ancestro) => {
            const item = document.createElement('li');
            item.className = 'breadcrumb-item';

            if (ancestro === null) {
                item.classList.add('active');
                item.setAttribute('aria-current', 'page');
                item.textContent = categoria.nombre;
            } else {
                const enlace = document.createElement('a');
                enlace.href = `?parent_id=${ancestro.id}`;
                enlace.textContent = ancestro.nombre;
                item.append(enlace);
            }

            lista.append(item);
        });
        miga.hidden = false;
    }

    document.querySelectorAll('[data-nombre-grupo]').forEach((lugar) => {
        lugar.textContent = categoria.nombre;
    });

    const nueva = document.querySelector('[data-nueva-en-grupo]');
    if (nueva) nueva.textContent = `Nueva subcategoría de ${categoria.nombre}`;

    raiz.querySelector('[data-listado-filas]').dataset.vacio = `«${categoria.nombre}» no tiene subcategorías.`;
}

// Una fila de la tabla. La ubicación sólo si no está a la vista en la miga de pan.
function llenarFila(fila, categoria, enUnGrupo) {
    rellenar(fila, categoria.id, {
        nombre: categoria.nombre,
        'dentro-de': enUnGrupo ? '' : categoria.dentro_de,
        estado: categoria.activo ? 'Activa' : 'Inactiva',
        productos: String(categoria.productos),
        subcategorias: String(categoria.subcategorias),
    });

    fila.querySelector('[data-campo="dentro-de"]').hidden = enUnGrupo || !categoria.dentro_de;

    const estado = fila.querySelector('[data-campo="estado"]');
    estado.classList.toggle('text-bg-dark', categoria.activo);
    estado.classList.toggle('text-bg-secondary', !categoria.activo);

    // Las subcategorías se recorren con el mismo filtro: es la navegación del árbol.
    const entrar = fila.querySelector('[data-entrar]');
    const ninguna = fila.querySelector('[data-sin-subcategorias]');
    entrar.hidden = categoria.subcategorias === 0;
    ninguna.hidden = categoria.subcategorias > 0;
    entrar.setAttribute('aria-label', `Ver las ${categoria.subcategorias} subcategorías de ${categoria.nombre}`);

    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${categoria.nombre}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (eliminar) {
        const dependencias = categoria.subcategorias + categoria.productos;
        eliminar.setAttribute('aria-label', `Eliminar ${categoria.nombre}`);
        eliminar.dataset.confirmar = dependencias > 0
            ? `«${categoria.nombre}» tiene ${categoria.subcategorias} subcategoría(s) y ${categoria.productos} producto(s). No se eliminará: se desactivará. ¿Continuar?`
            : `¿Eliminar la categoría «${categoria.nombre}»?`;
    }
}

/** El listado de categorías, con el filtro «Mostrar» y la miga de pan pedidos a la API. */
export function iniciarListadoDeCategorias() {
    const raiz = document.querySelector('[data-pantalla="categorias"]');
    if (!raiz) return;

    const grupo = grupoDeLaUrl();
    const mostrar = document.querySelector('select[data-opciones-grupos]');

    if (mostrar) {
        todasLasPaginas('/categorias?forma=lista&con_hijas=1')
            .then((categorias) => llenarOpcionesDeCategoria(mostrar, porRuta(categorias), {
                seleccionada: grupo,
                prefijo: 'Subcategorías de ',
            }))
            .catch(() => {
                // El módulo central ya avisó; el filtro queda con «Todas» y «principales».
            })
            .finally(() => iniciarSelectBuscable(mostrar));
    }

    if (grupo) {
        api.get(`/categorias/${grupo}`).then(({ datos }) => pintarGrupo(raiz, datos)).catch(() => {});
    }

    iniciarListado(raiz, {
        ruta: '/categorias?forma=lista',
        llenar: (fila, categoria) => llenarFila(fila, categoria, grupo !== null),
        filtros: ['q', 'estado'],
    });
}

// El aviso antes de desactivar: qué contenido activo tiene, en palabras.
function textoDelContenido({ subcategorias, productos }) {
    const partes = [
        subcategorias ? `${subcategorias} ${subcategorias === 1 ? 'subcategoría activa' : 'subcategorías activas'}` : null,
        productos ? `${productos} ${productos === 1 ? 'producto activo' : 'productos activos'}` : null,
    ].filter(Boolean);

    return `Esta categoría contiene ${partes.join(' y ')}. Si la desactivás, siguen como están: los productos se pueden seguir vendiendo.`;
}

/** El formulario: trae la categoría y sus padres posibles; al guardar vuelve a su grupo con el aviso. */
export function iniciarFormularioDeCategoria() {
    const formulario = document.querySelector('form[data-formulario-categoria]');
    if (!formulario) return;

    const id = formulario.dataset.categoria;
    const enviar = formulario.querySelector('[type="submit"]');
    const padre = formulario.querySelector('select[name="parent_id"]');
    const listado = formulario.dataset.volver;
    const liberar = cargando(enviar);

    Promise.all([
        id ? api.get(`/categorias/${id}`).then(({ datos }) => datos) : Promise.resolve(null),
        todasLasPaginas(`/categorias/padres-posibles${id ? `?categoria=${id}` : ''}`),
    ]).then(([categoria, padres]) => {
        llenarOpcionesDeCategoria(padre, padres, {
            seleccionada: categoria ? categoria.parent_id : (formulario.dataset.padreInicial || null),
        });
        iniciarSelectBuscable(padre);

        if (categoria) {
            formulario.nombre.value = categoria.nombre;
            formulario.orden.value = categoria.orden;
            formulario.activo.checked = categoria.activo;
            document.querySelector('[data-titulo-categoria]').textContent = `Editar «${categoria.nombre}»`;
            document.querySelectorAll('[data-volver-al-grupo]').forEach((enlace) => {
                enlace.href = categoria.parent_id ? `${listado}?parent_id=${categoria.parent_id}` : listado;
            });

            if (categoria.subcategorias > 0) {
                document.getElementById('ayudaPadre').textContent = `Tiene ${categoria.subcategorias} subcategoría(s), que se mueven con ella. Solo aparecen los lugares donde entran todas.`;
            }

            const { subcategorias, productos } = categoria.contenido_activo;
            const aviso = formulario.querySelector('[data-aviso-contenido]');
            if (aviso && categoria.activo && (subcategorias || productos)) {
                aviso.querySelector('[data-contenido-texto]').textContent = textoDelContenido(categoria.contenido_activo);
                aviso.hidden = false;
            }
        }

        liberar();
    }).catch(() => {
        // El módulo central ya lo mostró; sin la categoría o sus padres no hay qué guardar.
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        // `activo` y `parent_id` viajan siempre: la API no adivina (M-31).
        const datos = {
            nombre: formulario.nombre.value,
            parent_id: padre.value === '' ? null : Number(padre.value),
            orden: formulario.orden.value === '' ? null : Number(formulario.orden.value),
            activo: formulario.activo.checked,
            desactivar_contenido: formulario.desactivar_contenido?.checked ?? false,
        };

        const terminar = cargando(enviar);

        try {
            const respuesta = id
                ? await api.patch(`/categorias/${id}`, datos, { formulario })
                : await api.post('/categorias', datos, { formulario });
            const grupo = respuesta.datos.parent_id;

            // Vuelve al grupo donde estaba cargando, no a la raíz.
            completadoAlVolver(respuesta.mensaje, grupo ? `${listado}?parent_id=${grupo}` : listado);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            terminar();
        }
    });
}
