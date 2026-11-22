// La pantalla de alta y edición de una dirección, por la API (tramo E). La dirección de la edición y
// las demás del cliente salen de su ficha (`GET /clientes/{id}`): no hay un endpoint de una sola.

import { api } from '../api/cliente';
import { cargando, completadoAlVolver, limpiarErrores } from '../api/estados';

const TEXTOS = {
    primera: 'Es la primera dirección de este cliente, así que va a ser la predeterminada.',
    unica: 'Hoy es la predeterminada. Destildar esto no la cambia: para cambiarla, marcá otra dirección como predeterminada.',
    otra: 'Es la que se va a ofrecer primero al armar un envío. Marcarla desmarca la que lo sea hoy.',
};

// La lista de provincias es un Tom Select si ya se armó: ahí el valor se pone por su API.
function elegirProvincia(select, valor) {
    if (select.tomselect) select.tomselect.setValue(valor, true);
    else select.value = valor;
}

/** El formulario: trae la ficha del cliente; al guardar vuelve a ella con el aviso. */
export function iniciarFormularioDeDireccion() {
    const formulario = document.querySelector('form[data-formulario-direccion]');
    if (!formulario) return;

    const { cliente: clienteId, direccion: direccionId } = formulario.dataset;
    const enviar = formulario.querySelector('[type="submit"]');
    const ayuda = formulario.querySelector('[data-ayuda-predeterminada]');
    const liberar = cargando(enviar);

    api.get(`/clientes/${clienteId}`).then(({ datos }) => {
        document.querySelector('[data-campo="cliente"]').textContent = datos.razon_social;

        const direcciones = datos.direcciones ?? [];

        if (direccionId) {
            const direccion = direcciones.find((d) => String(d.id) === direccionId);
            if (!direccion) throw new Error('direccion_ajena');

            ['calle', 'numero', 'piso_depto', 'localidad', 'codigo_postal'].forEach((campo) => {
                formulario[campo].value = direccion[campo] ?? '';
            });
            elegirProvincia(formulario.provincia, direccion.provincia);
            formulario.es_predeterminada.checked = direccion.es_predeterminada;
            ayuda.textContent = direccion.es_predeterminada && direcciones.length > 1 ? TEXTOS.unica : TEXTOS.otra;
        } else {
            // Si es la primera, el servicio la marca predeterminada igual: la casilla llega marcada para que
            // la pantalla no diga una cosa y el resultado sea otra.
            const esLaPrimera = direcciones.length === 0;
            elegirProvincia(formulario.provincia, 'Santa Cruz');
            formulario.es_predeterminada.checked = esLaPrimera;
            ayuda.textContent = esLaPrimera ? TEXTOS.primera : TEXTOS.otra;
        }

        liberar();
    }).catch(() => {
        // El módulo central ya lo mostró; sin la ficha no hay qué guardar.
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        // `es_predeterminada` viaja siempre: la API no adivina una casilla ausente (M-31).
        const datos = {
            calle: formulario.calle.value,
            numero: formulario.numero.value,
            piso_depto: formulario.piso_depto.value,
            localidad: formulario.localidad.value,
            provincia: formulario.provincia.value,
            codigo_postal: formulario.codigo_postal.value,
            es_predeterminada: formulario.es_predeterminada.checked,
        };

        const libre = cargando(enviar);

        try {
            const respuesta = direccionId
                ? await api.patch(`/clientes/${clienteId}/direcciones/${direccionId}`, datos, { formulario })
                : await api.post(`/clientes/${clienteId}/direcciones`, datos, { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            libre();
        }
    });
}
