// El ingreso y la salida de la administración, por la API (tramo C), y las dos contraseñas (tramo I): la
// propia y la que se fija con el enlace. La credencial la maneja el módulo central: estas pantallas nunca
// la ven.

import { api, cambiarContrasena, ErrorDeApi, ingresar, salir } from '../api/cliente';
import { avisar, cargando, completadoAlVolver, limpiarErrores } from '../api/estados';

const MOTIVOS = {
    vencida: 'Tu sesión venció. Ingresá de nuevo.',
    terminada: 'Tu sesión terminó. Ingresá de nuevo.',
};

/** El formulario de ingreso: envía a la API, bloquea el botón mientras corre y va al inicio. */
export function iniciarIngreso() {
    const formulario = document.getElementById('formularioIngreso');
    if (!formulario) return;

    const motivo = MOTIVOS[new URLSearchParams(window.location.search).get('sesion')];
    if (motivo) avisar('aviso', motivo);

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(formulario.querySelector('[type="submit"]'));

        try {
            if (await ingresar(formulario.email.value, formulario.password.value, formulario)) {
                window.location.assign(formulario.dataset.destino);

                return;
            }
        } catch {
            // El módulo central ya lo mostró: errores por campo, demasiados intentos o sin conexión.
        }

        liberar();
    });
}

/** El botón de cerrar sesión del menú. */
export function iniciarSalida() {
    document.querySelectorAll('[data-accion="salir"]').forEach((boton) => {
        boton.addEventListener('click', () => {
            cargando(boton);
            // Si el servidor no contesta, la credencial igual se olvida y se va al ingreso.
            salir().catch(() => {});
        });
    });
}

/** Cambiar la contraseña propia: la cuenta sale de /yo; al terminar vuelve al panel con el aviso. */
export function iniciarCambioDeContrasena() {
    const formulario = document.querySelector('form[data-formulario-contrasena]');
    if (!formulario) return;

    api.get('/yo').then(({ datos }) => {
        formulario.email.value = datos.email;
        document.querySelector('[data-campo="cuenta-email"]').textContent = ` (${datos.email})`;
    }).catch((error) => {
        // El módulo central ya lo mostró; el formulario sirve igual sin el correo a la vista.
        if (!(error instanceof ErrorDeApi)) throw error;
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(formulario.querySelector('[type="submit"]'));

        try {
            const respuesta = await cambiarContrasena({
                password_actual: formulario.password_actual.value,
                password: formulario.password.value,
                password_confirmation: formulario.password_confirmation.value,
            }, formulario);

            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            liberar();
        }
    });
}

/**
 * Fijar la contraseña con el enlace. No abre sesión: una cuenta de gestión va al ingreso con el aviso;
 * una de la tienda ve que ya puede entrar desde la app (D-26).
 */
export function iniciarRestablecimiento() {
    const formulario = document.querySelector('form[data-formulario-restablecer]');
    if (!formulario) return;

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(formulario.querySelector('[type="submit"]'));

        try {
            const respuesta = await api.post('/contrasena/restablecer', {
                token: formulario.token.value,
                email: formulario.email.value,
                password: formulario.password.value,
                password_confirmation: formulario.password_confirmation.value,
            }, { formulario });

            if (respuesta.datos.ambito === 'gestion') {
                completadoAlVolver(respuesta.mensaje, formulario.dataset.ingreso);

                return;
            }

            formulario.hidden = true;
            document.querySelector('[data-cuenta-tienda-lista]').hidden = false;
        } catch {
            // El módulo central ya lo mostró: el enlace vencido o usado va arriba, la contraseña en su campo.
            liberar();
        }
    });
}
