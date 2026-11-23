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
import { iniciarIngreso, iniciarSalida } from './pantallas/sesion';
import { iniciarFormularioDeMarca, iniciarListadoDeMarcas } from './pantallas/marcas';
import { iniciarFormularioDeCategoria, iniciarListadoDeCategorias } from './pantallas/categorias';
import { iniciarFormularioDeProducto, iniciarListadoDeProductos } from './pantallas/productos';
import { iniciarComparadorDeProveedores, iniciarEdicionDeVinculo } from './pantallas/producto-proveedores';
import { iniciarFormularioDeDireccion } from './pantallas/direcciones';
import { iniciarFormularioDeCliente, iniciarListadoDeClientes } from './pantallas/clientes';
import { iniciarFormularioDeProveedor, iniciarListadoDeProveedores } from './pantallas/proveedores';
import { iniciarFormularioDeFactor, iniciarFormularioDeImpuesto, iniciarPantallaDePrecios, iniciarSimulador } from './pantallas/precios';
import { iniciarAjusteMasivo, iniciarHistorialDeAjustes } from './pantallas/ajuste-masivo';
import { iniciarAjusteDeStock, iniciarKardex } from './pantallas/stock';
import { iniciarFichaDeCompra, iniciarListadoDeCompras, iniciarSugeridos } from './pantallas/compras';
import { iniciarSelecciones } from './componentes/seleccion';
import { mostrarAvisoPendiente } from './api/estados';

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

// Política de precios: el margen equivalente, mientras se escribe el multiplicador.
document.addEventListener('DOMContentLoaded', () => {
    const campo = document.getElementById('ganancia_global');
    const badge = document.getElementById('badgeMargen');
    const valor = document.getElementById('margenValor');
    const aviso = document.getElementById('margenGanancia');

    if (!campo || (!badge && !aviso)) return;

    const elementoConDatos = badge ?? aviso;
    // Se lee en cada cambio: desde el tramo F lo guardado llega por la API después de cargar la página.
    const guardado = () => Number(elementoConDatos.dataset.guardado);
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
            const esGuardado = Math.abs(multiplicador - guardado()) < 0.0001;
            badge.className = esGuardado
                ? 'badge bg-success-subtle text-success-emphasis border border-success-subtle'
                : 'badge bg-primary-subtle text-primary-emphasis border border-primary-subtle';
            badge.title = esGuardado
                ? 'Margen guardado actualmente'
                : 'Margen correspondiente al multiplicador ingresado (sin guardar)';
        }

        if (aviso) {
            if (Math.abs(multiplicador - guardado()) < 0.0001) {
                aviso.innerHTML = originalAviso;
            } else {
                aviso.textContent = `Con × ${formatear(multiplicador, 3)} el margen sería del `
                    + `${textoMargen} % sobre la venta. Todavía no está guardado.`;
            }
        }
    };

    campo.addEventListener('input', refrescar);
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


// Entrar y salir de la administración, por la API: ver pantallas/sesion.js
document.addEventListener('DOMContentLoaded', () => {
    iniciarIngreso();
    iniciarSalida();
});

// El aviso que dejó la pantalla anterior (una alta que volvió al listado): ver api/estados.js
document.addEventListener('DOMContentLoaded', () => mostrarAvisoPendiente());

// Las pantallas migradas a la API (tramos D a G): ver pantallas/
document.addEventListener('DOMContentLoaded', () => {
    iniciarListadoDeCompras();
    iniciarFichaDeCompra();
    iniciarSugeridos();
    iniciarPantallaDePrecios();
    iniciarFormularioDeImpuesto();
    iniciarFormularioDeFactor();
    iniciarSimulador();
    iniciarAjusteMasivo();
    iniciarHistorialDeAjustes();
    iniciarKardex();
    iniciarAjusteDeStock();
    iniciarListadoDeProveedores();
    iniciarFormularioDeProveedor();
    iniciarListadoDeClientes();
    iniciarFormularioDeCliente();
    iniciarFormularioDeDireccion();
    iniciarListadoDeMarcas();
    iniciarFormularioDeMarca();
    iniciarListadoDeCategorias();
    iniciarFormularioDeCategoria();
    iniciarListadoDeProductos();
    iniciarFormularioDeProducto();
    iniciarComparadorDeProveedores();
    iniciarEdicionDeVinculo();
});

// Selección de filas para un formulario GET (ajuste de precios, sugeridos): ver componentes/seleccion.js
document.addEventListener('DOMContentLoaded', () => iniciarSelecciones());
