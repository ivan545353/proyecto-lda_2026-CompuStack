// El kardex y el ajuste de inventario, por la API (tramo F de la Etapa 3). Los filtros siguen siendo un
// GET a la misma pantalla; lo que se pide a la API es la consulta de la URL tal cual.

import { api } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';

const dia = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
const hora = new Intl.DateTimeFormat('es-AR', { hour: '2-digit', minute: '2-digit' });

/** Los números del stock de un producto en sus `[data-campo]`. */
function pintarStock(lugar, producto) {
    rellenar(lugar, producto.id, {
        nombre: producto.nombre,
        codigo: producto.codigo,
        stock: String(producto.stock),
        stock_reservado: String(producto.stock_reservado),
        disponible: String(producto.disponible),
        stock_minimo: String(producto.stock_minimo),
    });
}

function llenarMovimiento(fila, movimiento) {
    const fecha = new Date(movimiento.fecha);
    const ingreso = movimiento.cantidad > 0;

    rellenar(fila, movimiento.id, {
        dia: dia.format(fecha),
        hora: hora.format(fecha),
        producto: movimiento.producto.nombre,
        codigo: movimiento.producto.codigo,
        tipo_texto: movimiento.tipo_texto,
        cantidad: `${ingreso ? '+' : ''}${movimiento.cantidad}`,
        stock_anterior: String(movimiento.stock_anterior),
        stock_resultante: String(movimiento.stock_resultante),
        stock_leido: `De ${movimiento.stock_anterior} a ${movimiento.stock_resultante} unidades`,
        motivo: movimiento.motivo ?? movimiento.origen_texto ?? '—',
        remito_texto: movimiento.remito_texto,
        registrado_por: movimiento.registrado_por,
    });

    fila.querySelector('[data-campo="cantidad"]').classList.add(ingreso ? 'text-success' : 'text-danger');
    fila.querySelector('[data-campo="remito_texto"]').hidden = movimiento.remito_texto === null;
}

// El panel del producto filtrado, y su nombre en el selector y en el chip.
async function cargarProducto(raiz, selector) {
    const panel = raiz.querySelector('[data-panel-producto]');

    try {
        const { datos: producto } = await api.get(`/productos/${raiz.dataset.producto}/stock`);
        const rotulo = `${producto.texto}${producto.activo ? '' : ' (dado de baja)'}`;

        pintarStock(panel, producto);
        rellenar(panel, producto.id, { pedidos: String(producto.reservas.pedidos), carritos: String(producto.reservas.carritos) });
        panel.querySelector('[data-reservas]').hidden = producto.stock_reservado === 0;
        panel.querySelector('[data-inactivo]').hidden = producto.activo;
        panel.hidden = false;

        const elegida = selector.querySelector(`option[value="${producto.id}"]`);
        if (elegida) elegida.textContent = rotulo;

        const chip = document.querySelector('[data-nombre-filtro="producto_id"]');
        if (chip) chip.textContent = producto.nombre;
    } catch {
        // El módulo central ya avisó; el kardex se ve igual, sin el panel.
    } finally {
        iniciarSelectBuscable(selector);
    }
}

// Quienes registraron algún movimiento, en el filtro «Lo registró».
async function cargarUsuarios(select) {
    try {
        const usuarios = await todasLasPaginas('/stock/usuarios');

        usuarios.forEach((usuario) => {
            const opcion = new Option(usuario.texto, String(usuario.id));
            opcion.selected = String(usuario.id) === select.dataset.seleccionada;
            select.append(opcion);
        });

        const elegido = usuarios.find((usuario) => String(usuario.id) === select.dataset.seleccionada);
        const chip = document.querySelector('[data-nombre-filtro="usuario_id"]');
        if (chip && elegido) chip.textContent = elegido.texto;
    } catch {
        // El módulo central ya avisó; el filtro queda en «Cualquiera».
    } finally {
        iniciarSelectBuscable(select);
    }
}

/** El kardex: el listado, el panel del producto filtrado y las opciones de los filtros. */
export function iniciarKardex() {
    const raiz = document.querySelector('[data-pantalla="kardex"]');
    if (!raiz) return;

    const formulario = raiz.querySelector('form[data-filtros-kardex]');
    const selector = formulario.producto_id;

    if (raiz.dataset.producto) cargarProducto(raiz, selector);
    else iniciarSelectBuscable(selector);

    cargarUsuarios(formulario.usuario_id);

    iniciarListado(raiz, {
        ruta: '/stock/movimientos',
        llenar: llenarMovimiento,
        filtros: ['producto_id', 'tipo', 'usuario_id', 'canal', 'desde', 'hasta'],
        formulario,
    });
}

/** El ajuste por conteo físico: pide el stock de referencia, muestra la diferencia y la registra. */
export function iniciarAjusteDeStock() {
    const formulario = document.querySelector('form[data-formulario-ajuste-stock]');
    if (!formulario) return;

    const tarjeta = document.querySelector('[data-stock-producto]');
    const enviar = formulario.querySelector('[type="submit"]');
    const aviso = document.getElementById('diferenciaAjuste');
    const ruta = `/productos/${formulario.dataset.producto}`;
    let actual = null;

    // La diferencia en vivo: el error de tipeo se delata solo cuando el aviso dice "+27".
    const refrescar = () => {
        if (actual === null || formulario.stock_contado.value === '') {
            aviso.textContent = '';
            return;
        }

        const diferencia = Number(formulario.stock_contado.value) - actual;

        aviso.textContent = diferencia === 0
            ? `El conteo coincide con las ${actual} registradas: no hay nada que ajustar.`
            : `Se va a registrar un movimiento de ${diferencia > 0 ? '+' : ''}${diferencia} unidad(es).`;
    };

    // El stock de referencia viaja oculto: si cambió al guardar, el servicio rechaza en lugar de pisar.
    const cargar = async () => {
        try {
            const { datos: producto } = await api.get(`${ruta}/stock`);
            actual = producto.stock;
            formulario.stock_esperado.value = String(producto.stock);
            formulario.stock_contado.placeholder = `Ej: ${producto.stock}`;
            pintarStock(tarjeta, producto);
            enviar.disabled = false;
            refrescar();
        } catch {
            // El módulo central ya avisó; sin la referencia no se puede guardar.
        }
    };

    formulario.stock_contado.addEventListener('input', refrescar);

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(enviar);

        try {
            const respuesta = await api.post(`${ruta}/ajustes-stock`, {
                stock_contado: formulario.stock_contado.value === '' ? null : Number(formulario.stock_contado.value),
                stock_esperado: Number(formulario.stock_esperado.value),
                motivo: formulario.motivo.value,
            }, { formulario });

            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch (error) {
            liberar();
            // Si alguien movió el stock mientras se contaba (409), se muestra el número nuevo.
            if (error?.estado === 409) await cargar();
        }
    });

    cargar();
}
