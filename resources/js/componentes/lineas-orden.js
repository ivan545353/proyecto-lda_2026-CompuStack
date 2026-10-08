import { iniciarSelectsBuscables } from './selects-buscables';

/**
 * Líneas repetibles de una orden de compra.
 *
 * Mejora progresiva: sin este archivo el formulario sigue funcionando con las
 * líneas que el servidor haya renderizado —al menos una—, y el servidor valida
 * exactamente lo mismo. Lo que se pierde es agregar filas y ver los subtotales.
 *
 * Las claves del arreglo no se renumeran nunca. PHP recibe `lineas[0]` y
 * `lineas[7]` como un arreglo de dos elementos, y las reglas `lineas.*.campo` se
 * aplican igual, así que un contador que sólo crece evita toda la clase de errores
 * que produce reindexar al quitar una fila del medio.
 */
export function iniciarLineasDeOrden(raiz = document) {
    const tabla = raiz.querySelector('[data-lineas-orden]');
    if (!tabla) return;

    const cuerpo = tabla.querySelector('tbody');
    const plantilla = tabla.querySelector('#plantillaLinea');
    const botonAgregar = raiz.querySelector('[data-agregar-linea]');
    const totalEl = tabla.querySelector('[data-total-orden]');
    const avisoVacio = raiz.querySelector('[data-sin-lineas]');
    

    if (!cuerpo || !plantilla) return;

    let proximoIndice = Number(tabla.dataset.proximoIndice ?? 0);

    const pesos = new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 2,
    });

    // El costo se escribe en formato argentino: 25.000,50
    const aNumero = (texto) => {
        const limpio = String(texto ?? '').trim().replace(/\./g, '').replace(',', '.');
        const numero = Number(limpio);

        return Number.isFinite(numero) ? numero : 0;
    };

    const filas = () => [...cuerpo.querySelectorAll('tr')];

    const refrescar = () => {
        let total = 0;

        filas().forEach((fila) => {
            const cantidad = Number(fila.querySelector('[data-cantidad]')?.value ?? 0);
            const costo = aNumero(fila.querySelector('[data-costo-unitario]')?.value);
            const subtotal = (Number.isFinite(cantidad) ? cantidad : 0) * costo;

            total += subtotal;

            const celda = fila.querySelector('[data-subtotal]');
            if (celda) celda.textContent = subtotal > 0 ? pesos.format(subtotal) : '—';
        });

        if (totalEl) totalEl.textContent = pesos.format(total);
        if (avisoVacio) avisoVacio.hidden = filas().length > 0;
    };

    const agregar = () => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        // El marcador se reemplaza sobre el HTML de la fila antes de insertarla,
        // así los name, los id y los for quedan consistentes entre sí.
        fila.innerHTML = fila.innerHTML.replaceAll('__INDICE__', String(proximoIndice));
        proximoIndice += 1;

        cuerpo.insertBefore(fila, plantilla);

        // El selector con búsqueda se inicia en DOMContentLoaded: una fila creada
        // después tiene que pedirlo.
        iniciarSelectsBuscables(fila);

        refrescar();
    };

    botonAgregar?.addEventListener('click', agregar);

    cuerpo.addEventListener('input', refrescar);

    // Al elegir un producto se sugiere su último costo, y sólo si el campo está
    // vacío: sobrescribir lo que el usuario escribió sería corregirlo.
    cuerpo.addEventListener('change', (evento) => {
        const select = evento.target.closest('[data-producto]');
        if (!select) return;

        const campoCosto = select.closest('tr')?.querySelector('[data-costo-unitario]');
        const sugerido = select.selectedOptions[0]?.dataset.costoSugerido;

        if (campoCosto && campoCosto.value.trim() === '' && sugerido && Number(sugerido) > 0) {
            campoCosto.value = new Intl.NumberFormat('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(Number(sugerido));

            refrescar();
        }
    });

    cuerpo.addEventListener('click', (evento) => {
        if (!evento.target.closest('[data-quitar-linea]')) return;

        evento.target.closest('tr').remove();
        refrescar();
    });

    // Un formulario de alta abre con una fila lista para usar.
    if (filas().length === 0) agregar();

    refrescar();
}