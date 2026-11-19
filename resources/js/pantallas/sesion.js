// El ingreso y la salida de la administración, por la API (tramo C). La credencial la maneja el
// módulo central: esta pantalla nunca la ve.

import { ingresar, salir } from '../api/cliente';
import { avisar, cargando, limpiarErrores } from '../api/estados';

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
