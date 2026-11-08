import { olvidarBusquedas } from './selects-buscables';
/**
 * Líneas repetibles de una orden de compra.
 */
export function iniciarLineasDeOrden(raiz = document) {
    const tabla = raiz.querySelector('[data-lineas-orden]');
    if (!tabla) return;

    const cuerpo = tabla.querySelector('tbody');
    const plantilla = tabla.querySelector('#plantillaLinea');

    if (!cuerpo || !plantilla) return;

    const buscador = raiz.querySelector('[data-buscador-producto]');
    const campoProveedor = raiz.querySelector('[data-proveedor-orden]');
    const faltaProveedor = raiz.querySelector('[data-falta-proveedor]');
    const totalEl = tabla.querySelector('[data-total-orden]');
    const avisoVacio = raiz.querySelector('[data-sin-lineas]');

    let proximoIndice = Number(tabla.dataset.proximoIndice ?? 0);

    const pesos = new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 2,
    });

    // El costo sugerido se escribe en el formato en que se tipea: 25.000,50
    const decimal = new Intl.NumberFormat('es-AR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    // El costo se escribe en formato argentino: 25.000,50
    const aNumero = (texto) => {
        const limpio = String(texto ?? '').trim().replace(/\./g, '').replace(',', '.');
        const numero = Number(limpio);

        return Number.isFinite(numero) ? numero : 0;
    };

    const filas = () => [...cuerpo.querySelectorAll('tr[data-linea]')];

    const idsCargados = () => filas()
        .map((fila) => fila.querySelector('[data-producto]')?.value ?? '')
        .filter((id) => id !== '');

    /**
     * Lo que el buscador no vuelve a ofrecer, y el olvido que va con eso.
     */
    const sincronizarExclusion = () => {
        if (!buscador) return;

        const lista = idsCargados().join(',');

        if (buscador.dataset.buscableExcluir === lista) return;

        buscador.dataset.buscableExcluir = lista;
        olvidarBusquedas(buscador);
    };

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

        sincronizarExclusion();
    };

    const seguirAlProveedor = () => {
        if (!buscador) return;

        const proveedorId = campoProveedor?.value ?? '';
        const sinProveedor = proveedorId === '';
        const plantillaUrl = tabla.dataset.productosUrl ?? '';

        // Si la vista no publicó la plantilla, se queda la URL que rindió el
        // servidor: vale más el recorte guardado que ninguno.
        if (plantillaUrl !== '') {
            buscador.dataset.buscableUrl = plantillaUrl
                .replace('__PROVEEDOR__', encodeURIComponent(proveedorId));
        }

        if (faltaProveedor) faltaProveedor.hidden = !sinProveedor;

        if (sinProveedor) buscador.tomselect?.disable();
        else buscador.tomselect?.enable();

        olvidarBusquedas(buscador);
    };

    /** Un renglón nuevo, ya con su producto, la cantidad en 1 y el foco en la cantidad. */
    const agregarProducto = (datos) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        fila.innerHTML = fila.innerHTML.replaceAll('__INDICE__', String(proximoIndice));
        proximoIndice += 1;

        const campoProducto = fila.querySelector('[data-producto]');
        const rotulo = fila.querySelector('[data-rotulo]');
        const campoCantidad = fila.querySelector('[data-cantidad]');
        const campoCosto = fila.querySelector('[data-costo-unitario]');

        const nombre = datos.text ?? '';

        if (campoProducto) campoProducto.value = String(datos.value ?? datos.id ?? '');
        if (rotulo) rotulo.textContent = nombre;

        // Los dos textos que nombran el renglón para un lector de pantalla, los
        // mismos que el servidor escribe en las filas que rinde él.
        fila.querySelectorAll('[data-rotulo-campo]').forEach((etiqueta) => {
            etiqueta.textContent = `${etiqueta.dataset.rotuloCampo} de ${nombre}`;
        });

        fila.querySelector('[data-quitar-linea]')
            ?.setAttribute('aria-label', `Quitar ${nombre} de la orden`);

        if (campoCantidad) campoCantidad.value = '1';

        // El último costo registrado para ese proveedor, si el servidor lo mandó. Sin
        // `precio.ver_costo` la clave no viene y sin costo cargado viene en null: las
        // dos cosas dejan el campo vacío para que se escriba, y por eso acá no se
        // distinguen. Lo que no se puede es afirmar un costo que no se recibió.
        if (campoCosto) {
            campoCosto.value = datos.costo === undefined || datos.costo === null
                ? ''
                : decimal.format(Number(datos.costo));
        }

        cuerpo.insertBefore(fila, plantilla);

        refrescar();

        campoCantidad?.focus();
        campoCantidad?.select();
    };

    // Elegir en el buscador ES agregar el renglón. Se limpia en silencio para no
    // volver a disparar este mismo evento.
    buscador?.addEventListener('change', () => {
        const elegido = buscador.value;

        if (elegido === '') return;

        const datos = buscador.tomselect?.options?.[elegido];

        buscador.tomselect?.clear(true);

        if (datos) agregarProducto(datos);
    });

    campoProveedor?.addEventListener('change', seguirAlProveedor);

    cuerpo.addEventListener('input', refrescar);
    cuerpo.addEventListener('change', refrescar);

    // El recorrido con teclado del renglón de compras tiene tres campos y no dos:
    // cantidad, costo, y de vuelta al buscador. Enter avanza en lugar de enviar el
    // formulario; sin JavaScript, Enter sigue enviando como en cualquier formulario.
    cuerpo.addEventListener('keydown', (evento) => {
        if (evento.key !== 'Enter') return;

        if (evento.target.matches('[data-cantidad]')) {
            evento.preventDefault();
            evento.target.closest('tr[data-linea]')?.querySelector('[data-costo-unitario]')?.focus();

            return;
        }

        if (evento.target.matches('[data-costo-unitario]')) {
            evento.preventDefault();
            buscador?.tomselect?.focus();
        }
    });

    cuerpo.addEventListener('click', (evento) => {
        if (!evento.target.closest('[data-quitar-linea]')) return;

        evento.target.closest('tr[data-linea]')?.remove();
        refrescar();

        // El producto que se quitó vuelve a estar disponible para elegir, y el foco
        // vuelve al único lugar desde donde se agrega.
        buscador?.tomselect?.focus();
    });

    seguirAlProveedor();
    refrescar();
}