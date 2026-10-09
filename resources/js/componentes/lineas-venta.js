import { iniciarSelectsBuscables } from './selects-buscables';

/**
 * Las líneas de una venta: producto, cantidad, y el precio que pone el servidor.
 *
 * Mejora progresiva. Sin este archivo el formulario sigue funcionando: el servidor
 * renderiza las filas con su precio y su subtotal, la validación hace exactamente el
 * mismo trabajo y los importes definitivos los calcula igual. Lo que se pierde es
 * agregar y quitar filas, y que los números se actualicen mientras se escribe.
 *
 * **No hay campo de precio y no se manda ninguno.** El precio se lee del catálogo en
 * el servidor, que es la única cosa que el sistema original hacía bien en este módulo
 * (la nota positiva de M-14). Acá sólo se muestra, y lo que se muestra es una vista
 * previa: si el precio cambió entre que se abrió la pantalla y se guardó, el servidor
 * usa el de ese momento y la ficha lo dice.
 *
 * **Qué precio le toca a cada fila** lo deciden dos mapas, y la regla es la misma que
 * aplica `VentaService::ajustarLineas()`: si el producto elegido ya estaba en la venta,
 * conserva su precio congelado; si entra ahora, se cotiza al de hoy. Estar en el mapa
 * de congelados es la única condición, así que cambiar el producto de una fila y
 * volver a elegir el original recupera su precio, igual que lo haría el servidor. En
 * el alta el mapa de congelados está vacío y todo se cotiza hoy.
 *
 * Los índices del arreglo no se renumeran nunca: PHP recibe `lineas[0]` y `lineas[7]`
 * como dos elementos, y un contador que sólo crece evita toda la clase de errores que
 * produce reindexar al quitar una fila del medio.
 *
 * A diferencia del armador de pedido, acá **no hay grupos y ninguna fila se mueve**,
 * así que `refrescar()` no borra nada. El error de la Fase 5 —un `input` sobre el
 * selector que borraba el grupo con la fila adentro, porque el `input` llega antes
 * del `change` y en ese instante el valor es nuevo pero la fila está todavía en el
 * grupo viejo— no tiene cómo reproducirse en este componente, y es por eso que es más
 * simple.
 */
export function iniciarLineasDeVenta(raiz = document) {
    const armador = raiz.querySelector('[data-venta]');
    if (!armador) return;

    const tabla = armador.querySelector('table');
    const cuerpo = armador.querySelector('tbody[data-lineas]');
    const plantilla = armador.querySelector('#plantillaLineaVenta');

    if (!tabla || !cuerpo || !plantilla) return;

    const botonAgregar = armador.querySelector('[data-agregar-linea]');
    const avisoVacio = armador.querySelector('[data-sin-lineas]');
    const subtotalEl = armador.querySelector('[data-subtotal-venta]');
    const descuentoEl = armador.querySelector('[data-descuento-venta]');
    const filaDescuento = armador.querySelector('[data-fila-descuento]');
    const totalEl = armador.querySelector('[data-total-venta]');
    const campoDescuento = document.getElementById('descuento_porcentaje');

    const leerJson = (id) => {
        try {
            return JSON.parse(document.getElementById(id)?.textContent ?? '{}');
        } catch {
            return {};
        }
    };

    const catalogo = leerJson('preciosDelCatalogo');
    const congelados = leerJson('preciosCongelados');

    let proximoIndice = Number(armador.dataset.proximoIndice ?? 0);

    const pesos = new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 2,
    });

    // El porcentaje se escribe en formato argentino: 7,5
    const aNumero = (texto) => {
        const limpio = String(texto ?? '').trim().replace(/\./g, '').replace(',', '.');
        const numero = Number(limpio);

        return Number.isFinite(numero) ? numero : 0;
    };

    // El servidor redondea a dos decimales antes de escribir en una columna
    // decimal(12,2). La vista previa hace lo mismo para no mostrar un total que
    // difiera del guardado por una fracción de centavo.
    const centavos = (numero) => Math.round(numero * 100) / 100;

    const filas = () => [...cuerpo.querySelectorAll('tr[data-linea]')];

    const precioDe = (productoId) => {
        if (productoId === '') return null;

        if (congelados[productoId] !== undefined) return Number(congelados[productoId]);

        return catalogo[productoId] ? Number(catalogo[productoId].precio) : null;
    };

    /** Escribe el precio, el subtotal y los dos avisos de una fila, y devuelve su subtotal. */
    const refrescarFila = (fila) => {
        const productoId = fila.querySelector('[data-producto]')?.value ?? '';
        const cantidad = Number(fila.querySelector('[data-cantidad]')?.value ?? 0);
        const precio = precioDe(productoId);

        const celdaPrecio = fila.querySelector('[data-precio]');
        if (celdaPrecio) celdaPrecio.textContent = precio === null ? '—' : pesos.format(precio);

        // Se avisa sólo cuando el precio congelado DIFIERE del de hoy: decir
        // «congelado» cuando coincide no informa nada y sólo agrega ruido.
        const nota = fila.querySelector('[data-nota-precio]');

        if (nota) {
            const hoy = catalogo[productoId] ? Number(catalogo[productoId].precio) : null;
            const esCongelado = congelados[productoId] !== undefined;

            nota.hidden = !(esCongelado && hoy !== null && centavos(hoy) !== centavos(precio));
        }

        const subtotal = precio === null || !Number.isFinite(cantidad) ? 0 : centavos(cantidad * precio);
        const celdaSubtotal = fila.querySelector('[data-subtotal]');

        if (celdaSubtotal) celdaSubtotal.textContent = subtotal > 0 ? pesos.format(subtotal) : '—';

        // Avisar no es impedir: un presupuesto no compromete stock, y se puede
        // cotizar lo que todavía no llegó. Lo que no se va a poder es cobrarlo.
        const avisoStock = fila.querySelector('[data-aviso-stock]');

        if (avisoStock) {
            const disponible = catalogo[productoId]?.disponible;
            const falta = disponible !== undefined && cantidad > disponible;

            avisoStock.hidden = !falta;

            if (falta) {
                avisoStock.textContent = disponible > 0
                    ? `Hay ${disponible} disponible(s)`
                    : 'Sin stock disponible';
            }
        }

        return subtotal;
    };

    const refrescar = () => {
        const subtotal = centavos(filas().reduce((suma, fila) => suma + refrescarFila(fila), 0));
        const porcentaje = campoDescuento ? aNumero(campoDescuento.value) : 0;
        const descuento = centavos((subtotal * porcentaje) / 100);

        if (subtotalEl) subtotalEl.textContent = pesos.format(subtotal);
        if (descuentoEl) descuentoEl.textContent = `− ${pesos.format(descuento)}`;
        if (filaDescuento) filaDescuento.hidden = descuento <= 0;
        if (totalEl) totalEl.textContent = pesos.format(centavos(subtotal - descuento));
        if (avisoVacio) avisoVacio.hidden = filas().length > 0;
    };

    const agregar = () => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        // El marcador se reemplaza sobre el HTML de la fila antes de insertarla, así
        // los name, los id y los for quedan consistentes entre sí.
        fila.innerHTML = fila.innerHTML.replaceAll('__INDICE__', String(proximoIndice));
        proximoIndice += 1;

        cuerpo.appendChild(fila);

        // El selector con búsqueda se inicia en DOMContentLoaded: una fila creada
        // después tiene que pedirlo.
        iniciarSelectsBuscables(fila);
        refrescar();
    };

    botonAgregar?.addEventListener('click', agregar);

    // Los dos: `input` cubre lo que se escribe, y `change` el selector, que es lo
    // que Tom Select dispara sobre el <select> nativo que queda debajo.
    tabla.addEventListener('input', refrescar);
    tabla.addEventListener('change', refrescar);
    campoDescuento?.addEventListener('input', refrescar);

    tabla.addEventListener('click', (evento) => {
        if (!evento.target.closest('[data-quitar-linea]')) return;

        evento.target.closest('tr[data-linea]')?.remove();
        refrescar();
    });

    if (filas().length === 0) agregar();

    refrescar();
}