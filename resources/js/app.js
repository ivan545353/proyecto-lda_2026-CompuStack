import './bootstrap';
import * as bootstrap from 'bootstrap';
import { iniciarSelectsBuscables } from './componentes/selects-buscables';
import { iniciarGestoresDeImagenes } from './componentes/gestor-imagenes';
import { iniciarLineasDeOrden } from './componentes/lineas-orden';
import { iniciarArmadorDePedido } from './componentes/lineas-pedido';
import { iniciarRecepcion } from './componentes/recepcion';
import { iniciarLineasDeVenta } from './componentes/lineas-venta';
import { iniciarGraficosDelPanel } from './componentes/graficos';
import { iniciarAltasRapidas } from './componentes/alta-rapida';
import { iniciarCobro } from './componentes/cobro';
import { iniciarOpcionesDeImpresion } from './componentes/impresion';

window.bootstrap = bootstrap;

// Tooltips de Bootstrap en toda la aplicación
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        new bootstrap.Tooltip(el, {
            container: 'body',
        });
    });
});

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

// Política de precios: el margen equivalente, mientras se escribe el multiplicador.
document.addEventListener('DOMContentLoaded', () => {
    const campo = document.getElementById('ganancia_global');
    const badge = document.getElementById('badgeMargen');
    const valor = document.getElementById('margenValor');
    const aviso = document.getElementById('margenGanancia');

    if (!campo || (!badge && !aviso)) return;

    const elementoConDatos = badge ?? aviso;
    const guardado = Number(elementoConDatos.dataset.guardado);
    const originalAviso = aviso ? aviso.innerHTML : '';

    // Mismo criterio que App\Support\Importe: con coma, el punto es separador
    // de miles; sin coma, el punto es decimal.
    const numero = (texto) => {
        const limpio = String(texto).trim();

        return Number(limpio.includes(',') ? limpio.replace(/\./g, '').replace(',', '.') : limpio);
    };

    const formatear = (v, decimales) =>
        v.toLocaleString('es-AR', { maximumFractionDigits: decimales });

    const refrescar = () => {
        const multiplicador = numero(campo.value);

        if (!Number.isFinite(multiplicador) || multiplicador <= 0) {
            if (valor) valor.textContent = '—';
            if (badge) {
                badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle';
                badge.title = 'Multiplicador no válido';
            }
            if (aviso) aviso.innerHTML = originalAviso;
            return;
        }

        const margen = (1 - 1 / multiplicador) * 100;
        const textoMargen = formatear(margen, 2);

        if (valor) {
            valor.textContent = textoMargen;
        }

        if (badge) {
            const esGuardado = Math.abs(multiplicador - guardado) < 0.0001;
            badge.className = esGuardado
                ? 'badge bg-success-subtle text-success-emphasis border border-success-subtle'
                : 'badge bg-primary-subtle text-primary-emphasis border border-primary-subtle';
            badge.title = esGuardado
                ? 'Margen guardado actualmente'
                : 'Margen correspondiente al multiplicador ingresado (sin guardar)';
        }

        if (aviso) {
            if (Math.abs(multiplicador - guardado) < 0.0001) {
                aviso.innerHTML = originalAviso;
            } else {
                aviso.textContent = `Con × ${formatear(multiplicador, 3)} el margen sería del `
                    + `${textoMargen} % sobre la venta. Todavía no está guardado.`;
            }
        }
    };

    campo.addEventListener('input', refrescar);
});

// Formulario de producto: el precio de contado sólo se escribe cuando el precio
// está fijado a mano. Deshabilitado no viaja en el POST, así que un producto que
// pasa al cálculo automático no manda un precio que el servidor va a rechazar.
// Mejora progresiva: sin JavaScript el campo queda editable y la validación
// explica por qué no corresponde.
document.addEventListener('DOMContentLoaded', () => {
    const manual = document.getElementById('precio_manual');
    const precio = document.getElementById('precio_contado');

    // En el alta la casilla va deshabilitada y marcada: no hay nada que alternar.
    if (!manual || !precio || manual.disabled) return;

    const refrescar = () => {
        precio.disabled = !manual.checked;
        precio.required = manual.checked;
    };

    manual.addEventListener('change', refrescar);
    refrescar();
});

// Líneas repetibles de la orden de compra: ver componentes/lineas-orden.js
document.addEventListener('DOMContentLoaded', () => iniciarLineasDeOrden());

// Armador de pedido: ver componentes/lineas-pedido.js
document.addEventListener('DOMContentLoaded', () => iniciarArmadorDePedido());

// Recepción de mercadería: ver componentes/recepcion.js
document.addEventListener('DOMContentLoaded', () => iniciarRecepcion());

// Líneas de la venta: ver componentes/lineas-venta.js
document.addEventListener('DOMContentLoaded', () => iniciarLineasDeVenta());

// Gráficos del panel: ver componentes/graficos.js
document.addEventListener('DOMContentLoaded', () => iniciarGraficosDelPanel());


// Alta de cliente desde la venta, en una ventana: ver componentes/alta-rapida.js
document.addEventListener('DOMContentLoaded', () => iniciarAltasRapidas());


// Pantalla de cobro: ver componentes/cobro.js
document.addEventListener('DOMContentLoaded', () => iniciarCobro());


// El diálogo «Opciones de impresión» de los listados: ver componentes/impresion.js
document.addEventListener('DOMContentLoaded', () => iniciarOpcionesDeImpresion());

document.addEventListener('DOMContentLoaded', () => {
    const operacion = document.getElementById('operacion');
    const alcanceTodos = document.getElementById('alcanceTodos');

    if (!operacion || !alcanceTodos) return;

    // Qué bloque muestra cada operación, y qué se le explica al elegirla.
    const operaciones = {
        precio: {
            bloque: 'campoPorcentaje',
            descripcion: 'Modifica el precio de venta actual en el porcentaje indicado, sin tocar el costo.',
        },
        costo: {
            bloque: 'campoPorcentaje',
            descripcion: 'Actualiza el costo de compra y recalcula el precio con la ganancia y los factores vigentes.',
        },
        ganancia: {
            bloque: 'campoGanancia',
            descripcion: 'Fija el mismo multiplicador sobre el costo neto para todo el conjunto y recalcula.',
        },
        redondeo: {
            bloque: 'campoMultiplo',
            descripcion: 'Lleva el precio de venta al múltiplo más cercano, sin tocar el costo ni la ganancia.',
        },
        recalculo: {
            bloque: 'avisoRecalculo',
            descripcion: 'Recalcula el precio desde el costo vigente y la parametrización, sin cambiar nada más.',
        },
    };

    const bloques = ['campoPorcentaje', 'campoGanancia', 'campoMultiplo', 'avisoRecalculo'];
    const explicacion = document.getElementById('explicacionOperacion');

    const refrescarOperacion = () => {
        const elegida = operaciones[operacion.value] ?? operaciones.precio;

        bloques.forEach((id) => {
            const bloque = document.getElementById(id);
            if (!bloque) return;

            const corresponde = id === elegida.bloque;
            bloque.classList.toggle('d-none', !corresponde);

            const campo = bloque.querySelector('input');

            if (campo) {
                campo.disabled = !corresponde;
                campo.required = corresponde;
            }
        });

        if (explicacion) {
            explicacion.textContent = elegida.descripcion;
        }
    };

    const filtros = document.getElementById('bloqueFiltrosRecorte');
    const avisoTodos = document.getElementById('avisoCatalogoCompleto');

    const refrescarAlcance = () => {
        const todos = alcanceTodos.checked;

        filtros?.classList.toggle('d-none', todos);
        avisoTodos?.classList.toggle('d-none', !todos);

        filtros?.querySelectorAll('input, select').forEach((campo) => {
            campo.disabled = todos;
            campo.tomselect?.[todos ? 'disable' : 'enable']();
        });
    };

    operacion.addEventListener('change', refrescarOperacion);

    document.querySelectorAll('input[name="alcance"]').forEach((radio) => {
        radio.addEventListener('change', refrescarAlcance);
    });

    refrescarOperacion();
    refrescarAlcance();
});


document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-seleccion]').forEach((formulario) => {
        const asociadas = formulario.id
            ? document.querySelectorAll(`input[type="checkbox"][name][form="${formulario.id}"]`)
            : [];

        const casillas = [...new Set([
            ...formulario.querySelectorAll('input[type="checkbox"][name]'),
            ...asociadas,
        ])];

        if (casillas.length === 0) return;

        const todas = formulario.id
            ? document.querySelector(`[data-seleccion-todas="${formulario.id}"]`)
            : null;
        const boton = formulario.querySelector('button[type="submit"]');
        const contador = formulario.querySelector('[data-seleccion-contador]');
        const sinMarcar = contador?.textContent.trim() ?? '';
        const conMarcadas = contador?.dataset.seleccionMarcadas ?? '';

        const refrescar = () => {
            const marcadas = casillas.filter((casilla) => casilla.checked).length;

            if (todas) {
                todas.checked = marcadas > 0 && marcadas === casillas.length;
                todas.indeterminate = marcadas > 0 && marcadas < casillas.length;
            }

            // El servidor lo rinde habilitado porque sin JS tiene que poder usarse; que
            // se apague con la selección vacía es comodidad, no el estado inicial.
            if (boton) {
                boton.disabled = marcadas === 0;
            }

            if (contador) {
                contador.textContent = marcadas === 0 || conMarcadas === ''
                    ? sinMarcar
                    : conMarcadas.replace('{n}', marcadas);
            }
        };

        todas?.addEventListener('change', () => {
            casillas.forEach((casilla) => (casilla.checked = todas.checked));
            refrescar();
        });

        casillas.forEach((casilla) => casilla.addEventListener('change', refrescar));

        refrescar();
    });
});