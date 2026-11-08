import { olvidarBusquedas } from './selects-buscables';

/**
 * Las líneas de una venta: producto, cantidad, y el precio que pone el servidor.
 */ 
export function iniciarLineasDeVenta(raiz = document) {
    const armador = raiz.querySelector('[data-venta]');
    if (!armador) return;

    const tabla = armador.querySelector('table');
    const cuerpo = armador.querySelector('tbody[data-lineas]');
    const plantilla = armador.querySelector('#plantillaLineaVenta');

    if (!tabla || !cuerpo || !plantilla) return;

    const buscador = armador.querySelector('[data-buscador-producto]');
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

    const numeroDe = (texto) =>
        texto === undefined || texto === null || texto === '' ? null : Number(texto);

    /** Lo que el servidor contó del producto de esa fila: su precio de hoy y su disponible. */
    const datosDe = (fila) => ({
        precio: numeroDe(fila.dataset.precioHoy),
        disponible: numeroDe(fila.dataset.disponible),
    });

    const precioDe = (productoId, datos) => {
        if (productoId === '') return null;

        if (congelados[productoId] !== undefined) return Number(congelados[productoId]);

        return datos.precio;
    };

    /** Escribe el precio, el subtotal y los dos avisos de una fila, y devuelve su subtotal. */
    const refrescarFila = (fila) => {
        const productoId = fila.querySelector('[data-producto]')?.value ?? '';
        const campoCantidad = fila.querySelector('[data-cantidad]');
        const cantidad = Number(campoCantidad?.value ?? 0);
        const datos = datosDe(fila);
        const precio = precioDe(productoId, datos);

        const celdaPrecio = fila.querySelector('[data-precio]');
        if (celdaPrecio) celdaPrecio.textContent = precio === null ? '—' : pesos.format(precio);

        // Se avisa sólo cuando el precio congelado DIFIERE del de hoy: decir
        // «congelado» cuando coincide no informa nada y sólo agrega ruido.
        const nota = fila.querySelector('[data-nota-precio]');

        if (nota) {
            const esCongelado = congelados[productoId] !== undefined;

            nota.hidden = !(esCongelado && datos.precio !== null
                && centavos(datos.precio) !== centavos(precio));
        }

        const subtotal = precio === null || !Number.isFinite(cantidad) ? 0 : centavos(cantidad * precio);
        const celdaSubtotal = fila.querySelector('[data-subtotal]');

        if (celdaSubtotal) celdaSubtotal.textContent = subtotal > 0 ? pesos.format(subtotal) : '—';

        // Avisar no es impedir: un presupuesto no compromete stock, y se puede
        // cotizar lo que todavía no llegó. Lo que no se va a poder es cobrarlo.
        const avisoStock = fila.querySelector('[data-aviso-stock]');
        const falta = datos.disponible !== null && cantidad > datos.disponible;

        if (avisoStock) {
            avisoStock.hidden = !falta;

            if (falta) {
                avisoStock.textContent = datos.disponible > 0
                    ? `Hay ${datos.disponible} disponible(s)`
                    : 'Sin stock disponible';
            }
        }

        // El borde acompaña al texto de abajo, nunca comunica solo.
        campoCantidad?.classList.toggle('border-warning', falta);

        return subtotal;
    };

        const idsCargados = () => filas()
        .map((fila) => fila.querySelector('[data-producto]')?.value ?? '')
        .filter((id) => id !== '');

    /**
     * Lo que el buscador no vuelve a ofrecer.
     *
     * Dos mitades que van juntas: el atributo, que el componente lee cada vez que le
     * pregunta al servidor, y el olvido de lo ya buscado, porque Tom Select memoriza
     * por texto tipeado y volver a tipear lo mismo mostraría el producto que acaba de
     * quedar cargado. Se compara antes de escribir: `refrescar()` corre en cada tecla
     * de cada cantidad, y olvidar ahí vaciaría el desplegable mientras se usa.
     */
    const sincronizarExclusion = () => {
        if (!buscador) return;

        const lista = idsCargados().join(',');

        if (buscador.dataset.buscableExcluir === lista) return;

        buscador.dataset.buscableExcluir = lista;
        olvidarBusquedas(buscador);
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

        sincronizarExclusion();
    };

    /** Un renglón nuevo, ya con su producto, la cantidad en 1 y el foco en la cantidad. */
    const agregarProducto = (datos) => {
        const fila = plantilla.content.firstElementChild.cloneNode(true);

        // El marcador se reemplaza sobre el HTML de la fila antes de insertarla, así
        // los name, los id y los for quedan consistentes entre sí.
        fila.innerHTML = fila.innerHTML.replaceAll('__INDICE__', String(proximoIndice));
        proximoIndice += 1;

        // El rótulo se escribe con textContent y el id con .value: el nombre de un
        // producto es texto que cargó un usuario, así que interpolarlo en el HTML de
        // la fila lo volvería ejecutable.
        const campoProducto = fila.querySelector('[data-producto]');
        const rotulo = fila.querySelector('[data-rotulo]');
        const campoCantidad = fila.querySelector('[data-cantidad]');

        const nombre = datos.text ?? '';

        if (campoProducto) campoProducto.value = String(datos.value ?? datos.id ?? '');
        if (rotulo) rotulo.textContent = nombre;

        // Los dos textos que nombran el renglón para un lector de pantalla, los
        // mismos que el servidor escribe en las filas que rinde él.
        const rotuloCantidad = fila.querySelector('[data-rotulo-cantidad]');
        const quitar = fila.querySelector('[data-quitar-linea]');

        if (rotuloCantidad) rotuloCantidad.textContent = `Cantidad de ${nombre}`;
        quitar?.setAttribute('aria-label', `Quitar ${nombre} de la venta`);

        fila.dataset.precioHoy = datos.precio ?? '';
        fila.dataset.disponible = datos.disponible ?? '';

        if (campoCantidad) campoCantidad.value = '1';

        cuerpo.appendChild(fila);

        refrescar();

        campoCantidad?.focus();
        campoCantidad?.select();
    };

    // Elegir en el buscador ES agregar el renglón: el buscador es un gesto y no un
    // campo con estado, así que se limpia y el renglón queda como único registro de
    // lo elegido. Se limpia en silencio para no volver a disparar este mismo evento.
    buscador?.addEventListener('change', () => {
        const elegido = buscador.value;

        if (elegido === '') return;

        const datos = buscador.tomselect?.options?.[elegido];

        buscador.tomselect?.clear(true);

        if (datos) agregarProducto(datos);
    });

    // Los dos: `input` cubre lo que se escribe y `change` el pegado y las flechas del
    // campo numérico.
    tabla.addEventListener('input', refrescar);
    tabla.addEventListener('change', refrescar);
    campoDescuento?.addEventListener('input', refrescar);

    // Enter en la cantidad no envía el formulario: devuelve el foco al buscador, y es
    // lo que hace que diez renglones entren sin tocar el mouse. Sin JavaScript, Enter
    // sigue enviando como en cualquier formulario.
    tabla.addEventListener('keydown', (evento) => {
        if (evento.key !== 'Enter' || !evento.target.matches('[data-cantidad]')) return;

        evento.preventDefault();
        buscador?.tomselect?.focus();
    });

    tabla.addEventListener('click', (evento) => {
        if (!evento.target.closest('[data-quitar-linea]')) return;

        evento.target.closest('tr[data-linea]')?.remove();
        refrescar();

        // El producto que se quitó vuelve a estar disponible para elegir, y el foco
        // vuelve al único lugar desde donde se agrega.
        buscador?.tomselect?.focus();
    });

    refrescar();
}