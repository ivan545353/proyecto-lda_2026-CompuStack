import { api, ErrorDeApi } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';

/**
 * Pantalla de recepción de mercadería. Desde el tramo G es un cliente de la API: las líneas y lo
 * pendiente llegan por `GET /compras/{orden}` y registrar es `POST /compras/{orden}/recepciones`.
 *
 * Es el mismo recurso que la pantalla de ajuste de inventario: mostrar el resultado
 * mientras se escribe. Un 30 escrito en lugar de un 3 se delata solo cuando la
 * columna «quedarían» pasa a decir «sobran 27».
 */
export function iniciarRecepcion(raiz = document) {
    const pantalla = raiz.querySelector('[data-pantalla="recepcion"]');
    const form = pantalla?.querySelector('form[data-recepcion]');
    if (!pantalla || !form) return;

    const tarjeta = pantalla.querySelector('[data-tarjeta-recepcion]');
    const plantilla = form.querySelector('template[data-plantilla-recepcion]');
    const cuerpo = plantilla.parentElement;
    const totalEl = form.querySelector('[data-total-recibido]');
    const botonTodo = tarjeta.querySelector('[data-recibir-todo]');
    const filas = () => [...form.querySelectorAll('tr[data-linea]')];

    const refrescar = () => {
        let total = 0;

        filas().forEach((fila) => {
            const pendiente = Number(fila.dataset.pendiente ?? 0);
            const campo = fila.querySelector('[data-recibidas]');
            const recibidas = campo ? Math.max(0, Number(campo.value ?? 0) || 0) : 0;

            total += recibidas;

            const quedan = fila.querySelector('[data-quedan]');
            const resto = pendiente - recibidas;

            // Si sobra, se avisa acá, pero la barrera es el servidor: rechaza la
            // recepción entera explicando qué hacer con el excedente.
            quedan.textContent = resto < 0 ? `sobran ${Math.abs(resto)}` : String(resto);
            quedan.classList.toggle('text-danger', resto < 0);
            quedan.classList.toggle('fw-semibold', resto < 0);
        });

        totalEl.textContent = total === 0
            ? 'Todavía no cargaste ninguna unidad.'
            : `Se van a registrar ${total} unidad${total === 1 ? '' : 'es'} y a mover el stock.`;
    };

    /** Una fila por línea de la orden. El `name` es la clave del 422: `cantidades.{línea}`. */
    const agregarFila = (linea) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);
        const pendiente = linea.cantidad_pendiente;
        const { producto } = linea;

        fila.dataset.pendiente = String(pendiente);
        fila.querySelector('[data-nombre]').textContent = producto.nombre;
        fila.querySelector('[data-codigos]').textContent = linea.codigo_proveedor
            ? `${producto.codigo} · para el proveedor: ${linea.codigo_proveedor}`
            : producto.codigo;
        fila.querySelector('[data-pedidas]').textContent = String(linea.cantidad_pedida);
        fila.querySelector('[data-ya-recibidas]').textContent = String(linea.cantidad_recibida);
        fila.querySelector('[data-pendientes]').textContent = String(pendiente);

        const campo = fila.querySelector('[data-recibidas]');
        const rotulo = fila.querySelector('[data-rotulo-recibidas]');

        if (pendiente === 0) {
            // No se ofrece el campo: no viaja en el pedido.
            campo.remove();
            rotulo.remove();
            fila.querySelector('[data-completa]').hidden = false;
        } else {
            campo.id = `cantidad_${linea.id}`;
            campo.name = `cantidades.${linea.id}`;
            campo.max = String(pendiente);
            campo.dataset.linea = String(linea.id);
            rotulo.htmlFor = campo.id;
            rotulo.textContent = `Unidades de ${producto.nombre} que llegaron`;
        }

        cuerpo.insertBefore(fila, plantilla);
    };

    botonTodo.addEventListener('click', () => {
        filas().forEach((fila) => {
            const campo = fila.querySelector('[data-recibidas]');

            if (campo) campo.value = fila.dataset.pendiente;
        });

        refrescar();
    });

    form.addEventListener('input', refrescar);

    form.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(form);

        const cantidades = {};
        form.querySelectorAll('[data-recibidas]').forEach((campo) => {
            cantidades[campo.dataset.linea] = campo.value;
        });

        const liberar = cargando(form.querySelector('button[type="submit"]'));

        try {
            const respuesta = await api.post(`/compras/${pantalla.dataset.orden}/recepciones`, {
                cantidades,
                remito_numero: form.elements.namedItem('remito_numero').value,
                remito_fecha: form.elements.namedItem('remito_fecha').value,
            }, { formulario: form });

            completadoAlVolver(respuesta.mensaje, pantalla.dataset.volver);
        } catch {
            // El módulo central ya marcó cada campo (422) o mostró el motivo (409: sobra, o ya no recibe).
            liberar();
        }
    });

    api.get(`/compras/${pantalla.dataset.orden}`).then(({ datos: orden }) => {
        // El número y el proveedor van en el encabezado, fuera de la pantalla.
        raiz.querySelector('[data-numero-orden]').textContent = orden.numero;
        raiz.querySelector('[data-proveedor-orden]').textContent = orden.proveedor.razon_social;
        form.querySelector('[data-pendientes-total]').textContent = String(orden.unidades_pendientes);

        orden.lineas.forEach(agregarFila);
        tarjeta.hidden = false;
        refrescar();
    }).catch((error) => {
        // Un error de la API ya lo mostró el módulo central; uno de programación no se calla.
        if (!(error instanceof ErrorDeApi)) throw error;
    }).finally(() => {
        pantalla.querySelector('[data-cargando]').hidden = true;
        pantalla.removeAttribute('aria-busy');
    });
}
