import './bootstrap';
import * as bootstrap from 'bootstrap';
import { iniciarSelectsBuscables } from './componentes/selects-buscables';
import { iniciarGestoresDeImagenes } from './componentes/gestor-imagenes';
import { iniciarLineasDeOrden } from './componentes/lineas-orden';

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

// Formulario de proveedor: la dirección del portal sólo corresponde a un canal.
// Deshabilitado no viaja en el POST, así que un proveedor que deja de operar por
// portal no manda una URL que el servidor va a rechazar. Es mejora progresiva:
// sin JavaScript el campo queda visible y la validación del servidor explica por
// qué no corresponde.
document.addEventListener('DOMContentLoaded', () => {
    const canal = document.getElementById('canal_pedido');
    const portal = document.getElementById('portal_url');

    if (!canal || !portal) return;

    const grupo = portal.closest('[data-grupo-portal]');

    const refrescar = () => {
        const corresponde = canal.value === 'portal_externo';

        portal.disabled = !corresponde;
        portal.required = corresponde;
        grupo?.classList.toggle('d-none', !corresponde);
    };

    canal.addEventListener('change', refrescar);
    refrescar();
});

// Ajuste de inventario: mostrar la diferencia mientras se escribe, para que el
// operario vea la consecuencia antes de guardar. Un 30 escrito en lugar de un 3
// se delata solo cuando el aviso dice "+27".
document.addEventListener('DOMContentLoaded', () => {
    const contado = document.getElementById('stock_contado');
    const aviso = document.getElementById('diferenciaAjuste');

    if (!contado || !aviso) return;

    const actual = Number(aviso.dataset.stockActual);

    const refrescar = () => {
        if (contado.value === '') {
            aviso.textContent = '';
            return;
        }

        const diferencia = Number(contado.value) - actual;

        aviso.textContent = diferencia === 0
            ? `El conteo coincide con las ${actual} registradas: no hay nada que ajustar.`
            : `Se va a registrar un movimiento de ${diferencia > 0 ? '+' : ''}${diferencia} unidad(es).`;
    };

    contado.addEventListener('input', refrescar);
    refrescar();
});

// Líneas repetibles de la orden de compra: ver componentes/lineas-orden.js
document.addEventListener('DOMContentLoaded', () => iniciarLineasDeOrden());