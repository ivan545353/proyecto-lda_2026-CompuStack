import { Modal } from 'bootstrap';
import { api } from '../api/cliente';
import { avisarEnFormulario, cargando, limpiarErrores } from '../api/estados';
import { elegirOpcion } from './selects-buscables';

/**
 * Altas desde una ventana flotante: postean al endpoint del alta de la API (`data-alta-rapida-api`,
 * el mismo que usa la pantalla de alta: un solo camino) y, al guardar, dejan elegido el registro nuevo
 * en el buscador de la pantalla.
 * El pedido y sus errores (422 en cada campo; 403, 419 y sin conexión arriba del formulario)
 * los trata el módulo central (tramo C); acá queda sólo lo que es de la ventana.
 */
export function iniciarAltasRapidas(raiz = document) {
    raiz.querySelectorAll('form[data-alta-rapida]').forEach((formulario) => {
        const destino = document.querySelector(formulario.dataset.altaRapida);
        const ventana = formulario.closest('.modal');
        const anuncio = formulario.dataset.altaRapidaAnuncio
            ? document.querySelector(formulario.dataset.altaRapidaAnuncio)
            : null;
        const enviar = formulario.querySelector('button[type="submit"]');

        if (!destino || !ventana) return;

        let elegido = null;

        ventana.addEventListener('shown.bs.modal', () => {
            formulario.querySelector('input[name]:not([type="hidden"]), select[name], textarea[name]')?.focus();
        });

        // El anuncio afirma qué cliente quedó elegido: si se elige otro, deja de ser cierto.
        destino.addEventListener('change', () => {
            if (anuncio && destino.value !== elegido) anuncio.textContent = '';
        });

        formulario.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            limpiarErrores(formulario);

            const liberar = cargando(enviar);
            let fila;

            try {
                const { datos } = await api.post(formulario.dataset.altaRapidaApi, Object.fromEntries(new FormData(formulario)), { formulario });
                fila = datos;
            } catch {
                liberar();
                return;
            }

            liberar();

            // Desde acá el registro ya existe: un fallo no puede decir «no se pudo guardar».
            if (!fila?.id) {
                avisarEnFormulario(formulario, 'error', 'Se guardó, pero no se pudo elegir acá. Buscalo por su nombre.');
                formulario.reset();
                return;
            }

            elegido = String(fila.id);
            elegirOpcion(destino, fila);
            formulario.reset();

            if (anuncio) anuncio.textContent = `Se creó «${fila.texto}» y quedó elegido.`;

            Modal.getOrCreateInstance(ventana).hide();
        });
    });
}
