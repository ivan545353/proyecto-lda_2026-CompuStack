import { Modal } from 'bootstrap';
import { elegirOpcion } from './selects-buscables';

/**
 * Altas desde una ventana flotante: postean a la ruta del alta normal pidiendo JSON y,
 * al guardar, dejan elegido el registro nuevo en el buscador de la pantalla.
 */

const FALLA = 'No se pudo guardar. Revisá la conexión y probá de nuevo.';

/** Lo que significa cada respuesta que no es ni éxito ni error de validación. */
const POR_ESTADO = {
    403: 'No tenés permiso para crear este registro.',
    419: 'La sesión venció. Hay que volver a entrar, y lo que no se guardó en esta pantalla se pierde.',
};

/** Escribe aria-describedby, o lo saca si queda vacío. */
const describir = (campo, ids) => {
    const limpio = ids.trim().replace(/\s+/g, ' ');

    if (limpio === '') {
        campo.removeAttribute('aria-describedby');
    } else {
        campo.setAttribute('aria-describedby', limpio);
    }
};

export function iniciarAltasRapidas(raiz = document) {
    raiz.querySelectorAll('form[data-alta-rapida]').forEach((formulario) => {
        const destino = document.querySelector(formulario.dataset.altaRapida);
        const ventana = formulario.closest('.modal');
        const anuncio = formulario.dataset.altaRapidaAnuncio
            ? document.querySelector(formulario.dataset.altaRapidaAnuncio)
            : null;
        const general = formulario.querySelector('[data-alta-rapida-error]');
        const enviar = formulario.querySelector('button[type="submit"]');

        if (!destino || !ventana) return;

        const campos = [...formulario.querySelectorAll('input[name], select[name], textarea[name]')]
            .filter((campo) => campo.type !== 'hidden');

        // Lo que cada campo describía de entrada, para volver a eso al limpiar los errores.
        campos.forEach((campo) => {
            campo.dataset.describe = campo.getAttribute('aria-describedby') ?? '';
        });

        let elegido = null;

        const avisar = (texto) => {
            if (!general) return;

            general.textContent = texto;
            general.hidden = texto === '';
        };

        const limpiar = () => {
            formulario.querySelectorAll('[data-error-de]').forEach((error) => error.remove());

            campos.forEach((campo) => {
                campo.classList.remove('is-invalid');
                describir(campo, campo.dataset.describe);
            });

            avisar('');
        };

        /** Los errores del 422, cada uno en su campo; los que no tienen campo, arriba. */
        const marcarErrores = (errores) => {
            const sueltos = [];
            let primero = null;

            Object.entries(errores).forEach(([nombre, mensajes]) => {
                const campo = formulario.elements.namedItem(nombre);
                const mensaje = String(mensajes?.[0] ?? '');

                if (!campos.includes(campo)) {
                    sueltos.push(mensaje);
                    return;
                }

                const error = document.createElement('div');

                error.id = `${campo.id}-error`;
                error.className = 'invalid-feedback';
                error.dataset.errorDe = nombre;
                error.textContent = mensaje;

                campo.insertAdjacentElement('afterend', error);
                campo.classList.add('is-invalid');
                describir(campo, `${campo.dataset.describe} ${error.id}`);

                primero ??= campo;
            });

            avisar(sueltos.join(' '));
            primero?.focus();
        };

        ventana.addEventListener('shown.bs.modal', () => campos[0]?.focus());

        // El anuncio afirma qué cliente quedó elegido: si se elige otro, deja de ser cierto.
        destino.addEventListener('change', () => {
            if (anuncio && destino.value !== elegido) anuncio.textContent = '';
        });

        formulario.addEventListener('submit', async (evento) => {
            evento.preventDefault();
            limpiar();

            if (enviar) enviar.disabled = true;

            try {
                let respuesta;

                try {
                    respuesta = await fetch(formulario.action, {
                        method: 'POST',
                        body: new FormData(formulario),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                } catch {
                    avisar(FALLA);
                    return;
                }

                if (respuesta.status === 422) {
                    const cuerpo = await respuesta.json().catch(() => ({}));

                    marcarErrores(cuerpo.errors ?? {});
                    return;
                }

                if (!respuesta.ok) {
                    avisar(POR_ESTADO[respuesta.status] ?? FALLA);
                    return;
                }

                // Desde acá el registro ya existe: un fallo no puede decir «no se pudo guardar».
                const fila = await respuesta.json().catch(() => null);

                if (!fila?.id) {
                    avisar('Se guardó, pero no se pudo elegir acá. Buscalo por su nombre.');
                    formulario.reset();
                    return;
                }

                elegido = String(fila.id);
                elegirOpcion(destino, fila);
                formulario.reset();

                if (anuncio) anuncio.textContent = `Se creó «${fila.texto}» y quedó elegido.`;

                Modal.getOrCreateInstance(ventana).hide();
            } finally {
                if (enviar) enviar.disabled = false;
            }
        });
    });
}