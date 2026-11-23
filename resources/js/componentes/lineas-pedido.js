import { api } from '../api/cliente';
import { avisar, cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { paraFormulario } from '../pantallas/formato';
import { iniciarSelectsBuscables } from './selects-buscables';

/**
 * El armador de pedido: líneas con su proveedor, agrupadas por proveedor. Desde el tramo G es un
 * cliente de la API: los renglones con los que se entra vienen de `GET /compras/armador` (los
 * sugeridos elegidos, en `?productos[]=`) y guardar es `POST /compras`.
 */
export function iniciarArmadorDePedido(raiz = document) {
    const pantalla = raiz.querySelector('[data-pantalla="armador"]');
    const armador = raiz.querySelector('[data-armador]');
    if (!pantalla || !armador) return;

    const formulario = pantalla.querySelector('form[data-formulario-armador]');

    const tabla = armador.querySelector('table');
    const plantillaLinea = armador.querySelector('#plantillaLineaPedido');
    const plantillaGrupo = armador.querySelector('#plantillaGrupoPedido');
    const sinGrupo = armador.querySelector('tbody[data-grupo=""]');
    const botonAgregar = armador.querySelector('[data-agregar-linea]');
    const totalEl = armador.querySelector('[data-total-pedido]');
    const resumenEl = armador.querySelector('[data-resumen-pedido]');
    const avisoVacio = armador.querySelector('[data-sin-lineas]');

    if (!tabla || !plantillaLinea || !plantillaGrupo || !sinGrupo) return;

    // La plantilla de la URL, con __ID__ donde va el producto.
    const urlProveedores = armador.dataset.proveedoresUrl ?? '';

    // Lo que ya se preguntó. Cambiar la cantidad de una fila no vuelve a pedir los
    // proveedores de su producto, y volver a elegir el mismo producto tampoco.
    const cacheProveedores = new Map();

    // El nombre de cada proveedor, para la cabecera de su grupo: lo dice la API al contestar
    // quién provee cada producto (el shell no trae la lista, regla 37).
    const nombres = new Map();

    let proximoIndice = Number(armador.dataset.proximoIndice ?? 0);

    const pesos = new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        minimumFractionDigits: 2,
    });

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

    const filas = () => [...tabla.querySelectorAll('tr[data-linea]')];
    /**
     * A quién se le puede pedir ese producto.
     */
    const proveedoresDe = async (productoId) => {
        if (productoId === '') return [];
        if (cacheProveedores.has(productoId)) return cacheProveedores.get(productoId);

        try {
            // Por el módulo central: el error ya lo trató y lo mostró él (tramo C).
            // Tramo D: `GET /productos/{p}/proveedores`, ya en el orden en que conviene elegir.
            const cuerpo = await api.get(urlProveedores.replace('__ID__', productoId));

            (cuerpo.datos ?? []).forEach((proveedor) => nombres.set(String(proveedor.id), proveedor.razon_social));
            cacheProveedores.set(productoId, cuerpo.datos ?? []);

            return cacheProveedores.get(productoId);
        } catch {
            return null;
        }
    };

    /** «Austral — $ 15.000,00 (el elegido)», con el costo sólo si el servidor lo mandó. */
    const etiquetaDe = (proveedor) => {
        // Sin `precio.ver_costo` la clave no viene, y decir «sin costo cargado»
        // afirmaría algo que el servidor no contestó.
        const costo = 'costo_ultimo' in proveedor
            ? ` — ${proveedor.costo_ultimo === null ? 'sin costo cargado' : pesos.format(Number(proveedor.costo_ultimo))}`
            : '';

        return proveedor.es_preferido
            ? `${proveedor.razon_social}${costo} (el elegido)`
            : `${proveedor.razon_social}${costo}`;
    };
    // ------------------------------------------------------------------
    // Grupos por proveedor
    // ------------------------------------------------------------------

    const grupoDe = (proveedorId) => {
        const clave = String(proveedorId);
        const existente = tabla.querySelector(`tbody[data-grupo="${clave}"]`);

        if (existente) return existente;

        const nombre = nombres.get(clave) ?? 'Proveedor';
        const cuerpo = plantillaGrupo.content.firstElementChild.cloneNode(true);

        cuerpo.dataset.grupo = clave;
        cuerpo.querySelector('[data-nombre]').textContent = nombre;

        // Alfabético, el mismo orden en que el servidor va a numerar las órdenes:
        // así el número de cada pedido coincide con el orden de los grupos en
        // pantalla y no hay que buscar cuál es cuál.
        const siguiente = [...tabla.querySelectorAll('tbody[data-grupo]')]
            .filter((otro) => otro.dataset.grupo !== '')
            .find((otro) =>
                otro.querySelector('[data-nombre]').textContent.localeCompare(nombre, 'es') > 0,
            );

        tabla.insertBefore(cuerpo, siguiente ?? null);

        return cuerpo;
    };

    const moverAlGrupo = (fila) => {
        const proveedorId = fila.querySelector('[data-proveedor]')?.value ?? '';
        const destino = proveedorId === '' ? sinGrupo : grupoDe(proveedorId);

        if (fila.parentElement !== destino) destino.appendChild(fila);
    };

    // ------------------------------------------------------------------
    // Proveedores de la línea y sugerencia de costo
    // ------------------------------------------------------------------

    const llenarProveedores = async (fila, preferido = null) => {
        const select = fila.querySelector('[data-proveedor]');
        if (!select) return;

        const productoId = fila.querySelector('[data-producto]')?.value ?? '';
        const elegido = preferido ?? select.value;

        if (productoId === '') {
            select.innerHTML = '';
            select.appendChild(new Option('Elegí primero el producto', ''));

            return;
        }

        const opciones = await proveedoresDe(productoId);

        // El usuario pudo cambiar el producto mientras se esperaba la respuesta.
        if ((fila.querySelector('[data-producto]')?.value ?? '') !== productoId) return;

        select.innerHTML = '';

        if (opciones === null) {
            select.appendChild(new Option('No se pudieron cargar los proveedores', ''));

            return;
        }

        select.appendChild(new Option('Elegí el proveedor', ''));

        opciones.forEach((proveedor) => {
            select.appendChild(new Option(etiquetaDe(proveedor), proveedor.id));
        });

        // Se conserva lo que el usuario había elegido si ese proveedor también provee
        // el producto nuevo. Si no, se propone el primero de la lista, que es el
        // preferido o el más barato: el mismo criterio que la reposición automática.
        const sigueValiendo = opciones.some((proveedor) => String(proveedor.id) === elegido);

        select.value = sigueValiendo
            ? elegido
            : (opciones[0] ? String(opciones[0].id) : '');
    };

    const sugerirCosto = async (fila) => {
        const campo = fila.querySelector('[data-costo-unitario]');
        if (!campo) return;

        const productoId = fila.querySelector('[data-producto]')?.value ?? '';
        const opciones = await proveedoresDe(productoId);
        const proveedorId = fila.querySelector('[data-proveedor]')?.value ?? '';

        const vinculo = (opciones ?? []).find((proveedor) => String(proveedor.id) === proveedorId);

        const sugerido = vinculo && vinculo.costo_ultimo !== undefined && vinculo.costo_ultimo !== null
            ? decimal.format(Number(vinculo.costo_ultimo))
            : '';

        // Se sobrescribe lo vacío y lo que el sistema mismo sugirió antes. Lo que el
        // usuario escribió NO se toca: corregirle un valor que cargó a mano es el
        // error de M-31 trasladado a la pantalla.
        const escrito = campo.value.trim();

        if (escrito === '' || escrito === (fila.dataset.costoSugerido ?? '')) {
            campo.value = sugerido;
        }

        fila.dataset.costoSugerido = sugerido;
    };

      // ------------------------------------------------------------------
    // Totales
    // ------------------------------------------------------------------

    const subtotalDe = (fila) => {
        const cantidad = Number(fila.querySelector('[data-cantidad]')?.value ?? 0);
        const costo = aNumero(fila.querySelector('[data-costo-unitario]')?.value);

        return (Number.isFinite(cantidad) ? cantidad : 0) * costo;
    };

    /**
     * Subtotales, resúmenes y total. **No borra nada**, y eso es el arreglo.
     *
     * Se la llama en cada `input`, y un `<select>` dispara `input` ANTES de
     * `change`: en ese instante el valor del selector ya es el nuevo, pero la fila
     * todavía está en el <tbody> del proveedor viejo. La primera versión agrupaba
     * por el VALOR del selector, así que veía ese grupo vacío y lo borraba con la
     * fila adentro. Y como la fila quedaba desprendida del documento, el `change`
     * que viene después ya no burbujea hasta la tabla: el manejador delegado nunca
     * corría y nadie la volvía a poner. La fila desaparecía.
     *
     * Por eso acá la única fuente de verdad es DÓNDE está cada fila, no qué dice su
     * selector: cada grupo se resume con las filas que de verdad contiene. Mover
     * filas y borrar grupos vacíos es trabajo de `moverAlGrupo()` y
     * `limpiarGrupos()`, que corren sólo en `change`, cuando el DOM ya está donde
     * tiene que estar.
     */
    const refrescar = () => {
        let total = 0;

        filas().forEach((fila) => {
            const subtotal = subtotalDe(fila);

            total += subtotal;

            const celda = fila.querySelector('[data-subtotal]');
            if (celda) celda.textContent = subtotal > 0 ? pesos.format(subtotal) : '—';
        });

        let pedidos = 0;
        let sinElegir = 0;

        tabla.querySelectorAll('tbody[data-grupo]').forEach((cuerpo) => {
            const propias = [...cuerpo.querySelectorAll('tr[data-linea]')];

            cuerpo.hidden = propias.length === 0;

            if (propias.length === 0) return;

            if (cuerpo.dataset.grupo === '') {
                sinElegir = propias.length;

                return;
            }

            pedidos += 1;

            const suma = propias.reduce((acumulado, propia) => acumulado + subtotalDe(propia), 0);
            const resumenGrupo = cuerpo.querySelector('[data-resumen-grupo]');

            if (resumenGrupo) {
                resumenGrupo.textContent =
                    `${propias.length} producto${propias.length === 1 ? '' : 's'} · ${pesos.format(suma)}`;
            }
        });

        if (resumenEl) {
            const falta = sinElegir > 0
                ? ` Falta elegir el proveedor en ${sinElegir} línea${sinElegir === 1 ? '' : 's'}.`
                : '';

            resumenEl.textContent = pedidos === 0
                ? `Todavía no hay ningún pedido armado.${falta}`
                : `Al guardar se van a crear ${pedidos} pedido${pedidos === 1 ? '' : 's'} en borrador, uno por proveedor.${falta}`;
        }

        if (totalEl) totalEl.textContent = pesos.format(total);
        if (avisoVacio) avisoVacio.hidden = filas().length > 0;
    };

    /**
     * Un grupo que se quedó sin líneas se va. El de «sin proveedor» no: se esconde,
     * porque es donde aterrizan las filas nuevas.
     *
     * Es la única función que borra, y se la llama sólo después de mover o de quitar
     * una fila. Nunca desde un evento de teclado.
     */
    const limpiarGrupos = () => {
        tabla.querySelectorAll('tbody[data-grupo]').forEach((cuerpo) => {
            if (cuerpo.querySelector('tr[data-linea]')) return;

            if (cuerpo.dataset.grupo === '') cuerpo.hidden = true;
            else cuerpo.remove();
        });
    };
    // ------------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------------

    /** Una fila nueva; con `linea` (un renglón de `/compras/armador`), ya cargada. */
    const agregar = async (linea = null) => {
        const fila = plantillaLinea.content.firstElementChild.cloneNode(true);

        // El marcador se reemplaza sobre el HTML de la fila antes de insertarla, así
        // los id y los for quedan consistentes entre sí.
        fila.innerHTML = fila.innerHTML.replaceAll('__INDICE__', String(proximoIndice));
        proximoIndice += 1;

        if (linea) {
            fila.querySelector('[data-producto]').append(
                new Option(`${linea.producto.codigo} — ${linea.producto.nombre}`, String(linea.producto.id), true, true),
            );
            fila.querySelector('[data-cantidad]').value = String(linea.cantidad_pedida);
            fila.querySelector('[data-costo-unitario]').value = paraFormulario(linea.costo_unitario);
            // Lo que propuso el sistema se puede reemplazar por la sugerencia del proveedor que se elija.
            fila.dataset.costoSugerido = paraFormulario(linea.costo_unitario);
        }

        sinGrupo.appendChild(fila);

        // El selector con búsqueda se inicia en DOMContentLoaded: una fila creada
        // después tiene que pedirlo.
        iniciarSelectsBuscables(fila);
        await llenarProveedores(fila, linea ? String(linea.proveedor.id) : null);
        moverAlGrupo(fila);
        limpiarGrupos();
        refrescar();
    };

    botonAgregar?.addEventListener('click', () => agregar());

    tabla.addEventListener('input', refrescar);

    tabla.addEventListener('change', async (evento) => {
        const fila = evento.target.closest('tr[data-linea]');
        if (!fila) return;

        if (evento.target.matches('[data-producto]')) {
            await llenarProveedores(fila);
            await sugerirCosto(fila);
            moverAlGrupo(fila);
            limpiarGrupos();
            refrescar();

            return;
        }

        if (evento.target.matches('[data-proveedor]')) {
            await sugerirCosto(fila);
            moverAlGrupo(fila);
            limpiarGrupos();
            refrescar();
        }
    });

    tabla.addEventListener('click', (evento) => {
        if (!evento.target.closest('[data-quitar-linea]')) return;

        evento.target.closest('tr[data-linea]').remove();
        limpiarGrupos();
        refrescar();
    });

    // ------------------------------------------------------------------
    // Guardar
    // ------------------------------------------------------------------

    // Los `name` se ponen al mandar, con el índice en que viaja cada fila: así un 422 de
    // `lineas.2.costo_unitario` cae en el campo de la tercera fila de la pantalla.
    const renglones = () => filas().map((fila, indice) => {
        const renglon = {};

        fila.querySelectorAll('[data-campo-linea]').forEach((campo) => {
            campo.name = `lineas.${indice}.${campo.dataset.campoLinea}`;
            renglon[campo.dataset.campoLinea] = campo.value;
        });

        return renglon;
    });

    formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const liberar = cargando(formulario.querySelector('button[type="submit"]'));

        try {
            const respuesta = await api.post('/compras', { lineas: renglones() }, { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó cada campo (422) o mostró el motivo.
            liberar();
        }
    });

    // ------------------------------------------------------------------
    // Arranque: los sugeridos elegidos, si se vino de esa pantalla
    // ------------------------------------------------------------------

    const elegidos = new URLSearchParams(window.location.search).getAll('productos[]');
    const consulta = new URLSearchParams(elegidos.map((id) => ['productos[]', id]));

    const mostrar = (hayPedibles) => {
        pantalla.querySelector('[data-cargando]').hidden = true;
        pantalla.querySelector('[data-sin-pedibles]').hidden = hayPedibles;
        formulario.hidden = !hayPedibles;
    };

    api.get(`/compras/armador${elegidos.length ? `?${consulta}` : ''}`).then(async (sobre) => {
        mostrar(sobre.hay_pedibles);
        if (!sobre.hay_pedibles) return;

        // Rechaza, no corrige (M-31): lo que dejó de hacer falta no entra, y se dice.
        if (sobre.aviso) avisar('aviso', sobre.aviso);

        for (const linea of sobre.datos) await agregar(linea);
        if (filas().length === 0) await agregar();
    }).catch(async () => {
        // El módulo central ya lo mostró (una lista mal pedida es 422): se arma a mano.
        mostrar(true);
        await agregar();
    });
}