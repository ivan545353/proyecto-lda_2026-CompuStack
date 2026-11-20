// Los proveedores de un producto, por la API (tramo D): el comparador y la edición de un vínculo.
// El shell trae la estructura; los vínculos, los disponibles y las acciones pasan por el módulo central.

import { api } from '../api/cliente';
import { cargando, completado, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { todasLasPaginas } from './listado';
import { paraFormulario, pesos } from './productos';

// La cabecera: el producto, con su categoría y su marca. Devuelve el nombre, para los textos de las filas.
async function pintarProducto(raiz, id) {
    try {
        const { datos } = await api.get(`/productos/${id}?forma=gestion`);
        raiz.querySelector('[data-dato="nombre"]').textContent = datos.nombre;
        raiz.querySelector('[data-dato="detalle"]').textContent = [
            `Código ${datos.codigo}`, datos.categoria.ruta, datos.marca?.nombre,
        ].filter(Boolean).join(' · ');

        return datos.nombre;
    } catch {
        // El módulo central ya lo avisó; la tabla funciona igual.
        return 'el producto';
    }
}

function mostrar(fila, selector, visible) {
    fila.querySelectorAll(selector).forEach((lugar) => { lugar.hidden = !visible; });
}

// Una fila del comparador: el texto siempre por textContent.
function llenarFila(fila, vinculo, producto, cuantos) {
    const { proveedor } = vinculo;
    const nombre = proveedor.razon_social;

    fila.querySelector('[data-campo="razon_social"]').textContent = nombre;
    fila.querySelector('[data-campo="detalle"]').textContent = [
        vinculo.codigo_proveedor ? `Su código: ${vinculo.codigo_proveedor}` : 'Sin código del proveedor',
        proveedor.plazo_entrega_dias ? `entrega en ${proveedor.plazo_entrega_dias} días` : null,
    ].filter(Boolean).join(' · ');

    const costo = fila.querySelector('[data-campo="costo"]');
    costo.textContent = vinculo.costo_ultimo === null ? 'Sin cargar' : pesos(vinculo.costo_ultimo);
    costo.classList.toggle('text-body-secondary', vinculo.costo_ultimo === null);

    const diferencia = fila.querySelector('[data-campo="diferencia"]');
    diferencia.hidden = !(vinculo.diferencia > 0);
    diferencia.textContent = vinculo.diferencia > 0 ? `+${vinculo.diferencia} % que el más barato` : '';

    mostrar(fila, '[data-marca="preferido"]', vinculo.es_preferido);
    mostrar(fila, '[data-marca="barato"]', vinculo.mas_barato);
    mostrar(fila, '[data-marca="inactivo"]', !proveedor.activo);

    const preferido = fila.querySelector('[data-accion="preferido"]');
    preferido.hidden = vinculo.es_preferido;
    preferido.dataset.proveedor = proveedor.id;
    preferido.setAttribute('aria-label', `Pedirle la reposición de ${producto} a ${nombre}`);

    const editar = fila.querySelector('[data-accion="editar"]');
    editar.href = editar.getAttribute('href').replace('__PROVEEDOR__', proveedor.id);
    editar.setAttribute('aria-label', `Editar el vínculo con ${nombre}`);

    // No se ofrece el camino que el sistema va a negar: con un pedido en curso, el motivo.
    const quitar = fila.querySelector('[data-accion="quitar"]');
    const bloqueo = fila.querySelector('[data-campo="bloqueo"]');
    const pedido = vinculo.pedido_en_curso;
    quitar.hidden = Boolean(pedido);
    bloqueo.hidden = !pedido;
    if (pedido) bloqueo.textContent = `No se puede quitar: ${pedido.numero} ${pedido.estado_texto.toLowerCase()}`;
    quitar.dataset.proveedor = proveedor.id;
    quitar.setAttribute('aria-label', `Quitar a ${nombre} de ${producto}`);
    quitar.dataset.confirmar = vinculo.es_preferido && cuantos > 1
        ? `¿Quitar a ${nombre} de «${producto}»? Es el elegido: el más antiguo de los que quedan va a pasar a serlo.`
        : `¿Quitar a ${nombre} de «${producto}»?`;
}

// La ayuda de la casilla dice lo que va a pasar de verdad.
function ayudaDePreferencia(formulario, { esElPrimero = false, esElElegido = false }) {
    const ayuda = formulario.querySelector('[data-ayuda-preferido]');
    if (esElPrimero) ayuda.textContent = 'Es el primer proveedor de este producto, así que va a quedar elegido.';
    else if (esElElegido) ayuda.textContent = 'Hoy es el elegido. Destildar esto no lo cambia: para cambiarlo, marcá otro.';
    else ayuda.textContent = 'Es a quien le va a pedir la reposición automática. Marcarlo desmarca al que lo sea hoy.';
}

// El cuerpo del alta o la edición: `es_preferido` viaja siempre, la API no adivina un checkbox ausente.
function cuerpo(formulario) {
    const datos = {
        costo_ultimo: formulario.costo_ultimo.value,
        codigo_proveedor: formulario.codigo_proveedor.value,
        es_preferido: formulario.es_preferido.checked,
    };
    if (formulario.proveedor_id) datos.proveedor_id = formulario.proveedor_id.value;

    return datos;
}

// Las opciones del alta: sólo los activos que todavía no están (la pantalla no ofrece lo que se rechaza).
function llenarDisponibles(select, disponibles) {
    select.tomselect?.destroy();
    select.replaceChildren(new Option('Elegí el proveedor', ''));
    disponibles.forEach((proveedor) => select.add(new Option(proveedor.razon_social, proveedor.id)));
    iniciarSelectBuscable(select);
}

/** El comparador: los vínculos ordenados por costo, sus acciones y el alta de uno nuevo. */
export function iniciarComparadorDeProveedores() {
    const raiz = document.querySelector('[data-pantalla="producto-proveedores"]');
    if (!raiz) return;

    const base = `/productos/${raiz.dataset.producto}/proveedores`;
    const filas = raiz.querySelector('[data-vinculos]');
    const plantilla = raiz.querySelector('template[data-vinculo]');
    const formulario = raiz.querySelector('form[data-formulario-vinculo]');
    const enviar = formulario.querySelector('[type="submit"]');
    let producto = '';

    function vacio(texto) {
        const celda = document.createElement('td');
        celda.colSpan = 4;
        celda.className = 'text-center text-body-secondary py-4';
        celda.textContent = texto;
        const fila = document.createElement('tr');
        fila.append(celda);
        filas.replaceChildren(fila);
    }

    async function cargar() {
        try {
            const [vinculos, disponibles] = await Promise.all([
                todasLasPaginas(`${base}?forma=comparador`),
                todasLasPaginas(`${base}?forma=disponibles`),
            ]);

            if (vinculos.length === 0) {
                vacio('Todavía no se le compra a nadie. Mientras no haya ninguno, la reposición automática no va a poder pedir este producto.');
            } else {
                filas.replaceChildren(...vinculos.map((vinculo) => {
                    const fila = plantilla.content.firstElementChild.cloneNode(true);
                    llenarFila(fila, vinculo, producto, vinculos.length);
                    return fila;
                }));
            }

            llenarDisponibles(formulario.proveedor_id, disponibles);
            raiz.querySelector('[data-con-disponibles]').hidden = disponibles.length === 0;
            raiz.querySelector('[data-sin-disponibles]').hidden = disponibles.length > 0;
            raiz.querySelector('[data-campo="sin-disponibles"]').textContent = vinculos.length === 0
                ? 'No hay ningún proveedor activo para cargar.'
                : 'Ya están cargados todos los proveedores activos.';
            ayudaDePreferencia(formulario, { esElPrimero: vinculos.length === 0 });
        } catch {
            vacio('No se pudieron traer los proveedores.');
        }
    }

    filas.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('[data-accion="preferido"], [data-accion="quitar"]');
        if (!boton) return;
        const quitar = boton.dataset.accion === 'quitar';
        if (quitar && !window.confirm(boton.dataset.confirmar)) return;

        const liberar = cargando(boton);
        try {
            const ruta = `${base}/${boton.dataset.proveedor}`;
            const respuesta = quitar ? await api.delete(ruta) : await api.post(`${ruta}/preferido`, {});
            completado(respuesta.mensaje);
            await cargar();
        } catch {
            // El módulo central ya dio el aviso (un pedido en curso es 409, con su motivo).
            liberar();
        }
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);
        const liberar = cargando(enviar);

        try {
            const respuesta = await api.post(base, cuerpo(formulario), { formulario });
            formulario.reset();
            completado(respuesta.mensaje);
            liberar();
            await cargar();
        } catch {
            liberar();
        }
    });

    pintarProducto(raiz, raiz.dataset.producto).then((nombre) => {
        producto = nombre;
        return cargar();
    });
}

/** La edición de un vínculo: trae costo, código y preferencia; al guardar vuelve al comparador. */
export function iniciarEdicionDeVinculo() {
    const formulario = document.querySelector('form[data-formulario-vinculo][data-proveedor]');
    if (!formulario) return;

    const ruta = `/productos/${formulario.dataset.producto}/proveedores/${formulario.dataset.proveedor}`;
    const enviar = formulario.querySelector('[type="submit"]');
    const liberar = cargando(enviar);

    api.get(ruta).then(({ datos }) => {
        document.querySelector('[data-dato="producto"]').textContent = `De ${datos.producto.nombre}, código ${datos.producto.codigo}.`;
        formulario.querySelector('[data-dato="proveedor"]').textContent = datos.proveedor.razon_social;
        formulario.costo_ultimo.value = paraFormulario(datos.costo_ultimo);
        formulario.codigo_proveedor.value = datos.codigo_proveedor ?? '';
        formulario.es_preferido.checked = datos.es_preferido;
        ayudaDePreferencia(formulario, { esElElegido: datos.es_preferido });
        liberar();
    }).catch(() => {
        // El módulo central ya lo mostró; sin el vínculo no hay qué guardar.
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);
        const liberarEnvio = cargando(enviar);

        try {
            const respuesta = await api.patch(ruta, cuerpo(formulario), { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            liberarEnvio();
        }
    });
}
