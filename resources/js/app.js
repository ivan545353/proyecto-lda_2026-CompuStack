import './bootstrap';
import * as bootstrap from 'bootstrap';
import { iniciarSelectsBuscables } from './componentes/selects-buscables';
import { iniciarGestoresDeImagenes } from './componentes/gestor-imagenes';

window.bootstrap = bootstrap;

// Mostrar u ocultar la contraseña en el login
document.addEventListener('DOMContentLoaded', () => {
    const boton = document.getElementById('verContrasena');
    const campo = document.getElementById('password');

    if (!boton || !campo) return;

    boton.addEventListener('click', () => {
        const oculta = campo.type === 'password';
        campo.type = oculta ? 'text' : 'password';
        boton.querySelector('i').className = oculta ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});

// Pantalla de permisos: dependencia con "ver", casilla por módulo y contador
document.addEventListener('DOMContentLoaded', () => {
    const permisos = document.querySelectorAll('.js-permiso');
    if (!permisos.length) return;

    const contador = document.getElementById('contadorPermisos');

    const delModulo = (modulo) =>
        [...document.querySelectorAll(`.js-permiso[data-modulo="${modulo}"]`)];

    const verDe = (modulo) =>
        delModulo(modulo).find((c) => c.dataset.accion === 'ver');

    const refrescar = () => {
        document.querySelectorAll('.js-modulo').forEach((casilla) => {
            const hijos = delModulo(casilla.dataset.modulo);
            const marcados = hijos.filter((c) => c.checked).length;

            casilla.checked = marcados === hijos.length;
            casilla.indeterminate = marcados > 0 && marcados < hijos.length;
        });

        if (contador) {
            const total = [...permisos].filter((c) => c.checked).length;
            contador.textContent = `${total} de ${permisos.length} asignados`;
        }
    };

    document.querySelectorAll('.js-modulo').forEach((casilla) => {
        casilla.addEventListener('change', () => {
            delModulo(casilla.dataset.modulo).forEach((c) => (c.checked = casilla.checked));
            refrescar();
        });
    });

    permisos.forEach((casilla) => {
        casilla.addEventListener('change', () => {
            const modulo = casilla.dataset.modulo;
            const ver = verDe(modulo);

            if (!ver) {
                refrescar();
                return;
            }

            // Cualquier acción implica poder ver el módulo
            if (casilla.checked && casilla.dataset.accion !== 'ver') {
                ver.checked = true;
            }

            // Y al revés: quitar "ver" quita todo el módulo, porque el resto
            // quedaría inaccesible
            if (!casilla.checked && casilla.dataset.accion === 'ver') {
                delModulo(modulo).forEach((c) => (c.checked = false));
            }

            refrescar();
        });
    });

    refrescar();
});

// Llevar el foco al resumen de errores para que un lector de pantalla lo anuncie
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('resumenErrores')?.focus();
});

// Selectores con búsqueda: ver componentes/selects-buscables.js
document.addEventListener('DOMContentLoaded', () => iniciarSelectsBuscables());

// Gestor de imágenes: ver componentes/gestor-imagenes.js
document.addEventListener('DOMContentLoaded', () => iniciarGestoresDeImagenes());