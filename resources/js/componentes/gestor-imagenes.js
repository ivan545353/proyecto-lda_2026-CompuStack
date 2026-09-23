import Sortable from 'sortablejs';

/**
 * Gestor de imágenes con vista previa.
 *
 * Se aplica a todo .js-gestor-imagenes (componente Blade <x-gestor-imagenes>).
 * Las imágenes son tarjetas que se ordenan arrastrando o con botones; se
 * agregan de a una o de a varias, y se quitan al instante con opción de
 * deshacer. Nada se aplica hasta guardar el formulario.
 *
 * Al enviar, escribe lo que el servidor espera (ver ProductoRequest):
 *   imagenes[]      los archivos nuevos, en el orden en que aparecen
 *   imagenes_orden  JSON con la lista final: "actual:<ruta>" o "nueva:<n>"
 *
 * Con max = 1 (un logo, por ejemplo) no hay orden ni principal: escribe el
 * archivo en el campo simple (data-campo) y una marca de quita
 * (data-campo-quitar), que es el contrato que ya tenía el formulario de
 * marcas. La pantalla se unifica sin cambiar el servidor.
 * Mejora progresiva: si el navegador no permite armar la lista de archivos
 * de un campo (DataTransfer), el componente no se activa y queda el campo de
 * archivo común, con el que se puede agregar al final.
 *
 * Accesibilidad:
 *   - Arrastrar tiene alternativa con botones (WCAG 2.2, criterio 2.5.7).
 *   - Cada cambio se anuncia en una región aria-live.
 *   - El foco no se pierde: después de mover o quitar, queda en la tarjeta
 *     que corresponde.
 *   - Con "reducir movimiento" activado en el sistema, sin animaciones.
 */

const icono = (nombre) => `<i class="bi bi-${nombre}" aria-hidden="true"></i>`;

// Los nombres de archivo los elige el usuario. Un archivo llamado
// <img src=x onerror=alert(1)>.png se ejecutaría al insertarlo con
// innerHTML: todo texto variable pasa por acá.
const escapar = (texto) => String(texto).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

const listaDeNombres = (nombres) => nombres.map((n) => `«${escapar(n)}»`).join(', ');

function soportaListasDeArchivos() {
    try {
        return typeof DataTransfer !== 'undefined' && new DataTransfer() !== null;
    } catch {
        return false;
    }
}

export function iniciarGestoresDeImagenes(raiz = document) {
    if (!soportaListasDeArchivos()) {
        return;
    }

    raiz.querySelectorAll('.js-gestor-imagenes').forEach((contenedor) => {
        if (!contenedor.dataset.iniciado) {
            contenedor.dataset.iniciado = '1';
            new GestorDeImagenes(contenedor);
        }
    });
}

class GestorDeImagenes {
    constructor(contenedor) {
        this.contenedor    = contenedor;
        this.form          = contenedor.closest('form');
        this.max           = Number(contenedor.dataset.max);
        this.maxBytes      = Number(contenedor.dataset.maxKb) * 1024;
        this.tipos         = contenedor.dataset.tipos.split(',');
        this.descripcion   = contenedor.dataset.descripcion;
        this.campo          = contenedor.dataset.campo;
        this.campoQuitar    = contenedor.dataset.campoQuitar || null;
        this.etiquetaAgregar = contenedor.dataset.etiquetaAgregar;
        // Con una sola imagen no hay orden ni principal: el campo del
        // formulario es un archivo suelto y una marca de quita, no una lista.
        this.simple         = this.max === 1;
        this.reemplazar     = null;
        this.inputArchivos  = contenedor.querySelector(
            `input[type="file"][name="${this.campo}[]"], input[type="file"][name="${this.campo}"]`,
        );
        this.siguienteId   = 1;
        this.quitada       = null;   // la última quitada, para deshacer

        this.items = this.estadoInicial();
        this.construir();
        this.render();
    }

    nuevoId() {
        return String(this.siguienteId++);
    }

    /**
     * Las imágenes actuales. Si el formulario volvió con un error, se respeta
     * lo que el usuario había hecho con ellas (quitar, reordenar). Las nuevas
     * se perdieron: el navegador no conserva archivos entre envíos.
     */
    estadoInicial() {
        // El formulario volvió con un error y la imagen ya estaba quitada.
        if (this.contenedor.dataset.quitarPrevio === '1') {
            return [];
        }

        const actuales = JSON.parse(this.contenedor.dataset.actuales || '[]');
        const porRuta  = new Map(actuales.map((a) => [a.ruta, a]));
        let rutas      = actuales.map((a) => a.ruta);

        if (this.contenedor.dataset.ordenPrevio) {
            try {
                rutas = JSON.parse(this.contenedor.dataset.ordenPrevio)
                    .filter((t) => typeof t === 'string' && t.startsWith('actual:'))
                    .map((t) => t.slice('actual:'.length))
                    .filter((ruta) => porRuta.has(ruta));
            } catch {
                // Orden ilegible: se parte de las actuales.
            }
        }

        return rutas.map((ruta) => ({ id: this.nuevoId(), tipo: 'actual', ruta, url: porRuta.get(ruta).url }));
    }

    construir() {
        // Se quita del DOM, no se oculta: adentro hay una casilla que el
        // navegador enviaría igual, y con JavaScript el estado lo lleva el
        // campo oculto.
        this.contenedor.querySelectorAll('.js-sin-script').forEach((el) => el.remove());
        this.inputArchivos.classList.add('d-none');
        this.contenedor.querySelectorAll('.js-con-script').forEach((el) => el.classList.remove('d-none'));

        const montaje = this.contenedor.querySelector('[data-rol="montaje"]');
        montaje.innerHTML = `
            <p class="small text-body-secondary mb-2" data-rol="contador"></p>
            <ul class="gestor-imagenes__lista" data-rol="lista" aria-label="Imágenes de ${escapar(this.descripcion)}"></ul>
            <div data-rol="aviso"></div>
            <div class="visually-hidden" aria-live="polite" data-rol="anuncio"></div>
            <input type="file" class="d-none" ${this.simple ? '' : 'multiple'} accept="${escapar(this.tipos.join(','))}" data-rol="selector" tabindex="-1" aria-hidden="true">
            ${this.simple
                ? `<input type="hidden" name="${escapar(this.campoQuitar)}" value="0" data-rol="quitar">`
                : `<input type="hidden" name="${escapar(this.campo)}_orden" data-rol="orden">`}`;

        this.contador    = montaje.querySelector('[data-rol="contador"]');
        this.lista       = montaje.querySelector('[data-rol="lista"]');
        this.aviso       = montaje.querySelector('[data-rol="aviso"]');
        this.anuncio     = montaje.querySelector('[data-rol="anuncio"]');
        this.selector    = montaje.querySelector('[data-rol="selector"]');
        this.orden       = montaje.querySelector('[data-rol="orden"]');
        this.marcaQuitar = montaje.querySelector('[data-rol="quitar"]');

        this.lista.addEventListener('click', (e) => this.alHacerClic(e));
        this.aviso.addEventListener('click', (e) => this.alHacerClicEnAviso(e));

        this.selector.addEventListener('change', () => {
            this.agregarArchivos([...this.selector.files]);
            this.selector.value = '';   // permite volver a elegir el mismo archivo
        });

        this.activarArrastre();
        this.activarSoltarArchivos();
        this.form.addEventListener('submit', () => this.prepararEnvio());
    }

    // ------------------------------------------------------------------
    // Dibujo
    // ------------------------------------------------------------------

    render() {
        const cantidad = this.items.length;

        this.lista.innerHTML = '';
        this.items.forEach((item, i) => this.lista.appendChild(this.tarjeta(item, i, cantidad)));

        if (cantidad < this.max) {
            this.lista.appendChild(this.tarjetaAgregar(this.max - cantidad));
        }

        this.contador.textContent = this.simple
            ? ''
            : (cantidad >= this.max
                ? `${cantidad} de ${this.max} imágenes. Llegaste al máximo: quitá una para agregar otra.`
                : `${cantidad} de ${this.max} imágenes.`);
    }

    tarjeta(item, i, cantidad) {
        const posicion = i + 1;
        const alt = item.tipo === 'nueva'
            ? `Imagen nueva: ${item.archivo.name}`
            : `Imagen ${posicion} de ${this.descripcion}`;

        const li = document.createElement('li');
        li.className  = 'gestor-imagenes__tarjeta';
        li.dataset.id = item.id;

        li.innerHTML = `
            <div class="gestor-imagenes__marco">
                <img src="${escapar(item.url)}" alt="${escapar(alt)}" draggable="false">
                ${!this.simple && i === 0 ? '<span class="badge text-bg-dark gestor-imagenes__principal">Principal</span>' : ''}
                ${item.tipo === 'nueva' ? '<span class="badge text-bg-light border gestor-imagenes__nueva">Nueva</span>' : ''}
            </div>
            <div class="gestor-imagenes__acciones" role="group" aria-label="${this.simple ? escapar(this.descripcion) : `Imagen ${posicion}`}">
                ${this.simple ? `
                    <button type="button" class="btn btn-sm btn-light" data-accion="cambiar"
                        title="Cambiar" aria-label="Cambiar la imagen de ${escapar(this.descripcion)}">${icono('arrow-repeat')}</button>
                ` : `
                    <button type="button" class="btn btn-sm btn-light" data-accion="antes"
                        ${i === 0 ? 'disabled' : ''} title="Mover antes"
                        aria-label="Mover la imagen ${posicion} un lugar antes">${icono('chevron-left')}</button>
                    <button type="button" class="btn btn-sm btn-light" data-accion="principal"
                        ${i === 0 ? 'disabled' : ''} title="Hacer principal"
                        aria-label="Hacer principal la imagen ${posicion}">${icono(i === 0 ? 'star-fill' : 'star')}</button>
                    <button type="button" class="btn btn-sm btn-light" data-accion="despues"
                        ${i === cantidad - 1 ? 'disabled' : ''} title="Mover después"
                        aria-label="Mover la imagen ${posicion} un lugar después">${icono('chevron-right')}</button>
                `}
                <button type="button" class="btn btn-sm btn-light text-danger" data-accion="quitar"
                    title="Quitar" aria-label="Quitar ${this.simple ? `la imagen de ${escapar(this.descripcion)}` : `la imagen ${posicion}`}">${icono('trash')}</button>
            </div>`;

        return li;
    }

    tarjetaAgregar(lugares) {
        const li = document.createElement('li');
        li.className = 'gestor-imagenes__agregar';

        li.innerHTML = `
            <button type="button" class="gestor-imagenes__boton-agregar" data-accion="agregar">
                ${icono('plus-lg')}
                <span class="fw-semibold">${escapar(this.etiquetaAgregar)}</span>
                ${this.simple ? '' : `<span class="small text-body-secondary">${lugares === 1 ? 'Queda 1 lugar' : `Quedan ${lugares} lugares`}</span>`}
            </button>`;

        return li;
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    alHacerClic(evento) {
        const boton = evento.target.closest('button[data-accion]');
        if (!boton) {
            return;
        }

        const accion = boton.dataset.accion;
        if (accion === 'agregar') {
            this.selector.click();
            return;
        }

        const id     = boton.closest('.gestor-imagenes__tarjeta').dataset.id;
        const indice = this.items.findIndex((item) => item.id === id);

        switch (accion) {
            case 'cambiar':
                // No se quita todavía: si el archivo elegido no sirve, la
                // imagen actual tiene que seguir donde estaba.
                this.reemplazar = id;
                this.selector.click();
                break;
            case 'antes':     this.mover(indice, indice - 1, id, 'antes'); break;
            case 'despues':   this.mover(indice, indice + 1, id, 'despues'); break;
            case 'principal': this.mover(indice, 0, id, 'despues'); break;
            case 'quitar':    this.quitar(indice); break;
        }
    }

    mover(desde, hasta, id, enfocar) {
        const [item] = this.items.splice(desde, 1);
        this.items.splice(hasta, 0, item);

        this.render();
        this.enfocar(id, enfocar);
        this.anunciar(hasta === 0
            ? 'La imagen pasó a ser la principal.'
            : `Imagen movida al lugar ${hasta + 1} de ${this.items.length}.`);
    }

    quitar(indice) {
        const [item] = this.items.splice(indice, 1);
        this.quitada = { item, indice };

        this.render();

        // El foco va a la tarjeta que ocupó su lugar, o a "Agregar imagen".
        const siguiente = this.items[indice] ?? this.items[indice - 1];
        siguiente ? this.enfocar(siguiente.id, 'quitar') : this.lista.querySelector('[data-accion="agregar"]')?.focus();

        this.mostrarAviso('secondary', `
            <span>Se quitó la imagen. Se va a eliminar al guardar el producto.</span>
            <button type="button" class="btn btn-sm btn-outline-dark ms-auto" data-accion="deshacer">
                ${icono('arrow-counterclockwise')} Deshacer
            </button>`);
        this.anunciar('Imagen quitada. Podés deshacerlo con el botón Deshacer.');
    }

    deshacer() {
        if (!this.quitada) {
            return;
        }

        if (this.items.length >= this.max) {
            this.mostrarAviso('warning', `<span>No hay lugar para recuperarla: quitá otra imagen primero.</span>`);
            return;
        }

        const { item, indice } = this.quitada;
        this.items.splice(Math.min(indice, this.items.length), 0, item);
        this.quitada = null;

        this.render();
        this.limpiarAviso();
        this.enfocar(item.id, 'quitar');
        this.anunciar('Imagen recuperada.');
    }

    /**
     * Agrega las que se puedan y explica qué pasó con las demás. Formato y
     * peso se controlan antes que el lugar disponible: así el límite se
     * aplica sólo sobre imágenes que se podían aceptar.
     *
     * Es comodidad, no control: el servidor vuelve a validar todo.
     */
    agregarArchivos(archivos) {
        const lugares   = this.max - this.items.length + (this.reemplazar ? 1 : 0);
        const agregadas = [];
        const formato   = [];
        const peso      = [];
        const sinLugar  = [];

        for (const archivo of archivos) {
            if (!this.tipos.includes(archivo.type)) {
                formato.push(archivo.name);
            } else if (archivo.size > this.maxBytes) {
                peso.push(archivo.name);
            } else if (agregadas.length >= lugares) {
                sinLugar.push(archivo.name);
            } else {
                agregadas.push(archivo);
            }
        }

        agregadas.forEach((archivo) => this.items.push({
            id: this.nuevoId(), tipo: 'nueva', archivo, url: URL.createObjectURL(archivo),
        }));

        // Recién acá se quita la anterior: sólo si la nueva se pudo agregar.
        if (this.reemplazar && agregadas.length > 0) {
            this.items = this.items.filter((item) => item.id !== this.reemplazar);
        }

        this.reemplazar = null;

        this.render();

        const rechazadas = formato.length + peso.length + sinLugar.length;

        if (rechazadas === 0) {
            this.limpiarAviso();
            this.anunciar(agregadas.length === 1 ? 'Se agregó 1 imagen.' : `Se agregaron ${agregadas.length} imágenes.`);
            return;
        }

        const motivos = [
            sinLugar.length && `Sin lugar (el máximo es ${this.max}): ${listaDeNombres(sinLugar)}.`,
            formato.length  && `No son JPG, PNG ni WEBP: ${listaDeNombres(formato)}.`,
            peso.length     && `Superan ${this.maxBytes / 1024 >= 1024 ? `${this.maxBytes / 1048576} MB` : `${this.maxBytes / 1024} KB`}: ${listaDeNombres(peso)}.`,
        ].filter(Boolean);

        this.mostrarAviso('warning', `
            <div>
                <p class="fw-semibold mb-1">Se agregaron ${agregadas.length} de ${archivos.length} imágenes.</p>
                <ul class="mb-0 ps-3">${motivos.map((m) => `<li>${m}</li>`).join('')}</ul>
            </div>
            <button type="button" class="btn-close ms-auto" data-accion="cerrar-aviso" aria-label="Cerrar aviso"></button>`);

        this.anunciar(`Se agregaron ${agregadas.length} de ${archivos.length} imágenes. Las demás no se agregaron: el detalle está en el aviso.`);
    }

    // ------------------------------------------------------------------
    // Arrastrar
    // ------------------------------------------------------------------

    activarArrastre() {
        if (this.simple) {
            return;
        }

        const sinMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        Sortable.create(this.lista, {
            animation: sinMovimiento ? 0 : 150,
            draggable: '.gestor-imagenes__tarjeta',
            filter: 'button',          // tocar un botón no empieza un arrastre
            preventOnFilter: false,
            ghostClass: 'gestor-imagenes__tarjeta--fantasma',
            chosenClass: 'gestor-imagenes__tarjeta--elegida',
            // "Agregar imagen" queda siempre al final
            onMove: (e) => !e.related.classList.contains('gestor-imagenes__agregar'),
            onEnd: () => {
                const orden = [...this.lista.querySelectorAll('.gestor-imagenes__tarjeta')].map((li) => li.dataset.id);
                this.items.sort((a, b) => orden.indexOf(a.id) - orden.indexOf(b.id));

                this.render();
                this.anunciar('Orden actualizado. La primera imagen es la principal.');
            },
        });
    }

    /** Soltar archivos desde el escritorio sobre el gestor. */
    activarSoltarArchivos() {
        const traeArchivos = (e) => e.dataTransfer?.types.includes('Files');

        ['dragenter', 'dragover'].forEach((tipo) => this.contenedor.addEventListener(tipo, (e) => {
            if (!traeArchivos(e)) {
                return;   // es un arrastre de tarjeta, lo maneja Sortable
            }
            e.preventDefault();
            this.contenedor.classList.add('gestor-imagenes--soltando');
        }));

        this.contenedor.addEventListener('dragleave', (e) => {
            if (!this.contenedor.contains(e.relatedTarget)) {
                this.contenedor.classList.remove('gestor-imagenes--soltando');
            }
        });

        this.contenedor.addEventListener('drop', (e) => {
            if (!traeArchivos(e)) {
                return;
            }
            e.preventDefault();
            this.contenedor.classList.remove('gestor-imagenes--soltando');
            this.agregarArchivos([...e.dataTransfer.files]);
        });
    }

    // ------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------

    /** Arma imagenes[] e imagenes_orden justo antes de enviar. */
    prepararEnvio() {
        if (this.simple) {
            const item     = this.items[0] ?? null;
            const archivos = new DataTransfer();

            if (item?.tipo === 'nueva') {
                archivos.items.add(item.archivo);
            }

            this.inputArchivos.files = archivos.files;
            // Sin imagen: se pide quitarla. Con una nueva, el servicio
            // reemplaza y borra la anterior por su cuenta.
            this.marcaQuitar.value = item ? '0' : '1';

            return;
        }

        const archivos = new DataTransfer();

        const orden = this.items.map((item) => {
            if (item.tipo === 'actual') {
                return `actual:${item.ruta}`;
            }

            archivos.items.add(item.archivo);

            return `nueva:${archivos.files.length - 1}`;
        });

        this.inputArchivos.files = archivos.files;
        this.orden.value = JSON.stringify(orden);
    }

    // ------------------------------------------------------------------
    // Avisos y foco
    // ------------------------------------------------------------------

    alHacerClicEnAviso(evento) {
        const accion = evento.target.closest('[data-accion]')?.dataset.accion;

        if (accion === 'deshacer') {
            this.deshacer();
        } else if (accion === 'cerrar-aviso') {
            this.limpiarAviso();
        }
    }

    mostrarAviso(tipo, html) {
        this.aviso.innerHTML = `<div class="alert alert-${tipo} d-flex align-items-start gap-2 py-2 small mb-2">${html}</div>`;
    }

    limpiarAviso() {
        this.aviso.innerHTML = '';
    }

    anunciar(texto) {
        // Vaciar primero hace que un mismo texto se vuelva a anunciar.
        this.anuncio.textContent = '';
        window.setTimeout(() => { this.anuncio.textContent = texto; }, 50);
    }

    /** Foco en un botón de la tarjeta; si está deshabilitado, en el primero que no lo esté. */
    enfocar(id, accion) {
        const tarjeta = this.lista.querySelector(`[data-id="${id}"]`);

        (tarjeta?.querySelector(`[data-accion="${accion}"]:not(:disabled)`)
            ?? tarjeta?.querySelector('button:not(:disabled)'))?.focus();
    }
}