// Las pantallas de productos, por la API (tramo D): el listado de gestión y el formulario de alta y
// edición, con el desglose del precio y el historial. El shell trae la estructura y un @can por
// parte; lo que no se puede ver no viaja en la respuesta, así que acá sólo se pinta lo que llegó.

import { api } from '../api/cliente';
import { avisar, cargando, completadoAlVolver, limpiarErrores } from '../api/estados';
import { iniciarGestor } from '../componentes/gestor-imagenes';
import { iniciarSeleccion } from '../componentes/seleccion';
import { iniciarSelectBuscable } from '../componentes/selects-buscables';
import { iniciarListado, rellenar, todasLasPaginas } from './listado';
import { llenarOpcionesDeCategoria, porRuta } from './opciones-categoria';

// Los mismos formatos que App\Support\Importe: el JSON trae cadenas con punto y sin miles.
const conDecimales = (decimales) => new Intl.NumberFormat('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
const dosDecimales = conDecimales(2);

/** "1500.5" → "$ 1.500,50". */
export const pesos = (importe) => `$ ${dosDecimales.format(Number(importe ?? 0))}`;

/** "1500.5" → "1.500,50", para el value de un campo. */
export const paraFormulario = (importe) => (importe === null || importe === undefined || importe === '' ? '' : dosDecimales.format(Number(importe)));

/** Un número sin ceros sobrantes: "2.500" → "2,5". Para porcentajes y multiplicadores. */
const numero = (valor, decimales = 3) => (valor === null || valor === undefined || valor === ''
    ? ''
    : new Intl.NumberFormat('es-AR', { maximumFractionDigits: decimales }).format(Number(valor)));

const fecha = new Intl.DateTimeFormat('es-AR', { dateStyle: 'short', timeStyle: 'short' });

/** Agrega una opción por fila al final de un select. */
function llenarOpciones(select, filas, { texto, seleccionada = null }) {
    filas.forEach((fila) => {
        const opcion = document.createElement('option');
        opcion.value = String(fila.id);
        opcion.textContent = texto(fila);
        opcion.selected = seleccionada !== null && seleccionada !== '' && String(seleccionada) === String(fila.id);
        select.append(opcion);
    });
}

const conEstado = (nombre, activo, inactivo) => `${nombre}${activo ? '' : ` (${inactivo})`}`;

// ------------------------------------------------------------------
// El listado
// ------------------------------------------------------------------

// Una fila de la tabla. El texto va siempre por textContent.
function llenarFila(fila, producto) {
    const contado = producto.precios.contado.importe;
    const lista = producto.precios.lista.importe;

    rellenar(fila, producto.id, {
        nombre: producto.nombre,
        detalle: producto.marca ? `${producto.codigo} · ${producto.marca.nombre}` : producto.codigo,
        categoria: producto.categoria.ruta,
        contado: pesos(contado),
        lista: pesos(lista),
        costo: producto.costo_promedio === undefined ? '' : pesos(producto.costo_promedio),
        margen: producto.margen ? numero(producto.margen, 2) : '',
        disponible: String(producto.disponible),
        estado: producto.activo ? 'Activo' : 'Inactivo',
    });

    const imagen = fila.querySelector('[data-imagen]');
    if (producto.imagen) imagen.src = producto.imagen;
    imagen.hidden = !producto.imagen;
    fila.querySelector('[data-sin-imagen]').hidden = Boolean(producto.imagen);

    const avisos = {
        'categoria-inactiva': !producto.categoria.activo,
        'marca-inactiva': producto.marca !== null && !producto.marca.activo,
        'sin-proveedor': producto.sin_proveedor,
        inactivo: !producto.activo,
    };
    Object.entries(avisos).forEach(([aviso, visible]) => {
        fila.querySelector(`[data-aviso="${aviso}"]`).hidden = !visible;
    });

    fila.querySelector('[data-lista]').hidden = contado === lista;
    fila.querySelector('[data-a-mano]').hidden = !producto.precio_manual;
    const margen = fila.querySelector('[data-margen]');
    if (margen) margen.hidden = !producto.margen;

    fila.querySelector('[data-sin-stock]').hidden = producto.disponible > 0;
    fila.querySelector('[data-stock-bajo]').hidden = producto.disponible <= 0 || producto.disponible > producto.stock_minimo;

    const estado = fila.querySelector('[data-campo="estado"]');
    estado.classList.toggle('text-bg-dark', producto.activo);
    estado.classList.toggle('text-bg-secondary', !producto.activo);

    fila.querySelector('[data-marcar]')?.setAttribute('aria-label', `Marcar ${producto.nombre} para el ajuste de precios`);
    fila.querySelector('[data-accion="stock"]')?.setAttribute('aria-label', `Ver los movimientos de stock de ${producto.nombre}`);
    fila.querySelector('[data-accion="proveedores"]')?.setAttribute('aria-label', `Ver y administrar los proveedores de ${producto.nombre}`);
    fila.querySelector('[data-accion="editar"]')?.setAttribute('aria-label', `Editar ${producto.nombre}`);

    const eliminar = fila.querySelector('[data-listado-eliminar]');
    if (eliminar) {
        eliminar.setAttribute('aria-label', `Eliminar ${producto.nombre}`);
        eliminar.dataset.confirmar = `¿Eliminar «${producto.nombre}»? Si tiene stock o movimientos registrados, se va a desactivar en lugar de eliminarse.`;
    }
}

// Las opciones de cada filtro, de su propio endpoint; el chip del filtro puesto lleva su nombre.
const FILTROS = {
    categorias: {
        ruta: '/categorias?forma=lista',
        clave: 'categoria_id',
        llenar: (select, filas, seleccionada) => llenarOpcionesDeCategoria(select, porRuta(filas), { seleccionada }),
        nombre: (fila) => fila.nombre,
    },
    marcas: {
        ruta: '/marcas',
        clave: 'marca_id',
        llenar: (select, filas, seleccionada) => llenarOpciones(select, filas, { seleccionada, texto: (m) => conEstado(m.nombre, m.activo, 'inactiva') }),
        nombre: (fila) => fila.nombre,
    },
    proveedores: {
        ruta: '/proveedores',
        clave: 'proveedor_id',
        llenar: (select, filas, seleccionada) => llenarOpciones(select, filas, { seleccionada, texto: (p) => conEstado(p.razon_social, p.activo, 'inactivo') }),
        nombre: (fila) => fila.razon_social,
    },
};

function cargarFiltro(select) {
    const filtro = FILTROS[select.dataset.opciones];
    const seleccionada = select.dataset.seleccionada || null;

    todasLasPaginas(filtro.ruta)
        .then((filas) => {
            filtro.llenar(select, filas, seleccionada);

            const elegida = filas.find((fila) => String(fila.id) === String(seleccionada));
            const chip = document.querySelector(`[data-nombre-filtro="${filtro.clave}"]`);
            if (chip && elegida) chip.textContent = filtro.nombre(elegida);
        })
        .catch(() => {
            // El módulo central ya avisó; el filtro queda con «Todas».
        })
        .finally(() => iniciarSelectBuscable(select));
}

/** El listado de productos de gestión, con sus filtros pedidos a la API. */
export function iniciarListadoDeProductos() {
    const raiz = document.querySelector('[data-pantalla="productos"]');
    if (!raiz) return;

    document.querySelectorAll('select[data-opciones]').forEach(cargarFiltro);

    const ajuste = document.getElementById('formSeleccionAjuste');

    iniciarListado(raiz, {
        ruta: '/productos?forma=gestion',
        llenar: llenarFila,
        filtros: ['q', 'categoria_id', 'marca_id', 'proveedor_id', 'estado', 'stock'],
        alCargar: (sobre) => {
            if (!ajuste) return;
            ajuste.hidden = !sobre || sobre.datos.length === 0;
            if (!ajuste.hidden) iniciarSeleccion(ajuste);
        },
    });
}

// ------------------------------------------------------------------
// El formulario
// ------------------------------------------------------------------

// El precio de contado sólo se escribe con el precio fijado a mano. Deshabilitado no viaja, así que
// un producto que pasa al cálculo automático no manda un precio que el servidor va a rechazar.
function alternarPrecio(formulario) {
    const manual = formulario.precio_manual;
    const precio = formulario.precio_contado;

    if (manual.disabled) return;   // el alta: marcada y fija

    const refrescar = () => {
        precio.disabled = !manual.checked;
        precio.required = manual.checked;
    };

    manual.addEventListener('change', refrescar);
    refrescar();
}

// Una fila de la tabla del desglose: un rótulo (con un detalle en gris) y un importe.
function filaDeCalculo(rotulo, detalle, importe, { claseFila = '', claseImporte = '', destacada = false } = {}) {
    const fila = document.createElement('tr');
    if (claseFila) fila.className = claseFila;

    const th = document.createElement('th');
    th.scope = 'row';
    th.className = destacada ? 'h6 mb-0' : 'fw-normal';
    th.textContent = rotulo;
    if (detalle) {
        const gris = document.createElement('span');
        gris.className = 'text-body-secondary';
        gris.textContent = ` ${detalle}`;
        th.append(gris);
    }

    const td = document.createElement('td');
    td.className = `text-end${claseImporte ? ` ${claseImporte}` : ''}${destacada ? ' h6 mb-0' : ''}`;
    td.textContent = importe;

    fila.append(th, td);

    return fila;
}

// Cómo sale su precio: el aviso de precio manual, el desglose (si viajó) y el historial (si viajó).
function pintarPrecio(producto) {
    const seccion = document.querySelector('[data-seccion-precio]');
    if (!seccion) return;

    seccion.querySelector('[data-aviso-manual]').hidden = !producto.precio_manual;

    const tabla = seccion.querySelector('[data-calculo]');
    const sinCosto = seccion.querySelector('[data-sin-costo]');

    if (tabla && producto.calculo !== undefined) {
        const tieneCosto = Number(producto.costo_promedio) > 0;
        sinCosto.hidden = tieneCosto;
        tabla.hidden = !tieneCosto;

        if (tieneCosto) {
            const c = producto.calculo;
            const filas = [
                filaDeCalculo('Costo neto', '', pesos(c.costo_neto)),
                filaDeCalculo('Ganancia', `× ${numero(c.ganancia)}${producto.ganancia === null ? ' (heredada)' : ''}`,
                    pesos(Math.round(Number(c.costo_neto) * Number(c.ganancia) * 100) / 100), { claseImporte: 'text-body-secondary' }),
                ...c.factores.map((factor) => filaDeCalculo(
                    factor.nombre,
                    factor.modo === 'recupero'
                        ? `÷ ${numero(1 - Number(factor.porcentaje) / 100, 4)}`
                        : `× ${numero(1 + Number(factor.porcentaje) / 100, 4)}`,
                    `+ ${pesos(factor.importe)}`,
                    { claseImporte: 'text-body-secondary' },
                )),
                filaDeCalculo('Neto de venta', '', pesos(c.contado.neto), { claseFila: 'border-top' }),
                ...c.contado.impuestos.map((impuesto) => filaDeCalculo(impuesto.nombre, `${numero(impuesto.alicuota, 2)} %`, pesos(impuesto.importe))),
                filaDeCalculo('Precio calculado', '', pesos(c.contado.final), { claseFila: 'border-top', destacada: true }),
            ];

            // La comparación es lo que hace útil al panel: dice si el producto está al día o quedó atrás.
            if (producto.precios.contado.importe !== c.contado.final) {
                filas.push(filaDeCalculo('Hoy está en', '', pesos(producto.precios.contado.importe), { claseImporte: 'text-body-secondary' }));
                filas.at(-1).querySelector('th').classList.add('text-body-secondary');
            }

            filas.push(filaDeCalculo('Margen actual sobre la venta', '', producto.margen === null ? '—' : `${numero(producto.margen, 2)} %`));
            seccion.querySelector('[data-calculo-filas]').replaceChildren(...filas);
        }
    }

    const historial = seccion.querySelector('[data-historial]');
    const cambios = producto.historial ?? [];
    historial.hidden = cambios.length === 0;

    seccion.querySelector('[data-historial-filas]').replaceChildren(...cambios.map((cambio) => {
        const fila = document.createElement('tr');
        const celdas = [
            ['small', cambio.fecha ? fecha.format(new Date(cambio.fecha)) : ''],
            ['text-end', pesos(cambio.contado_anterior)],
            ['text-end', pesos(cambio.contado_nuevo)],
            ['d-none d-md-table-cell small text-body-secondary', cambio.motivo_texto],
            ['d-none d-md-table-cell small text-body-secondary', cambio.usuario ?? 'El sistema'],
        ];
        fila.append(...celdas.map(([clase, texto]) => {
            const celda = document.createElement('td');
            celda.className = clase;
            celda.textContent = texto;

            return celda;
        }));

        return fila;
    }));
}

// Los campos del formulario con la ficha de gestión.
function llenarFormulario(formulario, producto) {
    formulario.codigo.value = producto.codigo;
    formulario.nombre.value = producto.nombre;
    formulario.descripcion.value = producto.descripcion;
    formulario.precio_manual.checked = producto.precio_manual;
    formulario.precio_contado.value = paraFormulario(producto.precios.contado.importe);
    formulario.stock_minimo.value = producto.stock_minimo;
    formulario.cantidad_reposicion.value = producto.cantidad_reposicion;
    formulario.activo.checked = producto.activo;
    if (formulario.destacado) formulario.destacado.checked = producto.destacado;

    // La ganancia viaja con producto.editar: es quien abre este formulario.
    if (producto.ganancia !== undefined) {
        formulario.ganancia.value = numero(producto.ganancia);
        formulario.ganancia.placeholder = numero(producto.ganancia_aplicada);
    }

    document.querySelector('[data-titulo-producto]').textContent = `Editar «${producto.nombre}»`;
    document.querySelector('[data-precio-lista]').textContent = pesos(producto.precios.lista.importe);
    document.querySelector('[data-dato="disponible"]').textContent = String(producto.disponible);
    const costo = document.querySelector('[data-dato="costo"]');
    if (costo && producto.costo_promedio !== undefined) costo.textContent = pesos(producto.costo_promedio);
}

// Las categorías y marcas que se pueden elegir: las activas, y la que el producto ya tiene aunque esté
// inactiva (la validación la acepta si no cambia: ProductoRequest::referenciaActiva).
async function cargarOpciones(formulario, producto) {
    const [categorias, marcas, { datos: opciones }] = await Promise.all([
        todasLasPaginas('/categorias?forma=lista&estado=activas'),
        todasLasPaginas('/marcas?estado=activas'),
        api.get(`/productos/opciones${producto ? `?producto=${producto.id}` : ''}`),
    ]);

    const actual = producto?.categoria;
    if (actual && !categorias.some((c) => c.id === actual.id)) {
        categorias.push({ ...actual, nivel: actual.ruta.split(' › ').length });
    }
    llenarOpcionesDeCategoria(formulario.categoria_id, porRuta(categorias), { seleccionada: actual?.id ?? null });

    const marca = producto?.marca;
    if (marca && !marcas.some((m) => m.id === marca.id)) marcas.push(marca);
    llenarOpciones(formulario.marca_id, marcas, { seleccionada: marca?.id ?? null, texto: (m) => conEstado(m.nombre, m.activo, 'inactiva') });

    // El alta nace con el 21 %, como siempre.
    const elegida = producto?.alicuota_iva ?? '21.00';
    opciones.alicuotas.forEach(({ valor, texto }) => {
        const opcion = new Option(texto, valor, false, valor === elegida);
        formulario.alicuota_iva.append(opcion);
    });

    iniciarSelectBuscable(formulario.categoria_id);
    iniciarSelectBuscable(formulario.marca_id);
}

// El cuerpo del envío. Las casillas viajan siempre en 1 o 0: la API no adivina una ausente (M-31).
function cuerpo(formulario, id) {
    const datos = new FormData(formulario);

    datos.set('activo', formulario.activo.checked ? '1' : '0');
    datos.set('precio_manual', formulario.precio_manual.checked ? '1' : '0');
    if (formulario.destacado) datos.set('destacado', formulario.destacado.checked ? '1' : '0');
    // Con imágenes, PATCH no sirve: PHP no lee los archivos de un PATCH multipart (HL-110).
    if (id) datos.set('_method', 'PATCH');

    return datos;
}

/** El formulario: trae el producto y sus opciones; al guardar vuelve al listado con el aviso. */
export function iniciarFormularioDeProducto() {
    const formulario = document.querySelector('form[data-formulario-producto]');
    if (!formulario) return;

    const id = formulario.dataset.producto;
    const enviar = formulario.querySelector('[type="submit"]');
    const gestor = formulario.querySelector('.js-gestor-imagenes');
    const liberar = cargando(enviar);

    // El envío se escucha después de arrancar el gestor de imágenes: él arma imagenes[] e
    // imagenes_orden en su propio `submit`, que tiene que correr antes de leer el formulario.
    const escucharEnvio = () => formulario.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        limpiarErrores(formulario);

        const terminar = cargando(enviar);

        try {
            const respuesta = await api.post(id ? `/productos/${id}` : '/productos', cuerpo(formulario, id), { formulario });
            completadoAlVolver(respuesta.mensaje, formulario.dataset.volver);
        } catch {
            // El módulo central ya marcó los campos o dio el aviso.
            terminar();
        }
    });

    const ficha = id ? api.get(`/productos/${id}?forma=gestion`).then(({ datos }) => datos) : Promise.resolve(null);

    ficha.then(async (producto) => {
        await cargarOpciones(formulario, producto);

        if (producto) {
            llenarFormulario(formulario, producto);
            pintarPrecio(producto);
            if (gestor) iniciarGestor(gestor, producto.imagenes);
        }

        alternarPrecio(formulario);
        escucharEnvio();
        liberar();
    }).catch(() => {
        // El módulo central ya lo mostró; sin el producto o sus opciones no hay qué guardar.
    });

    // Recalcular escribe el precio en el producto: después se vuelve a pedir la ficha.
    document.querySelector('[data-recalcular]')?.addEventListener('click', async (evento) => {
        const boton = evento.currentTarget;
        const terminar = cargando(boton);

        try {
            // No recalcular (precio manual, sin costo) no es un error: es un aviso, no un éxito.
            const respuesta = await api.post(`/productos/${id}/recalcular`);
            avisar(respuesta.datos.aplicado ? 'exito' : 'aviso', respuesta.mensaje);
            const { datos } = await api.get(`/productos/${id}?forma=gestion`);
            llenarFormulario(formulario, datos);
            pintarPrecio(datos);
        } catch {
            // El módulo central ya lo mostró.
        } finally {
            terminar();
        }
    });
}
