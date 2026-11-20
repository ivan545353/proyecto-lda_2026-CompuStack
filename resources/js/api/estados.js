// Los seis estados de interfaz de la administración, los mismos que la app: carga, sin resultados,
// completado, error de validación, sin conexión y sesión vencida. Ninguno se dice sólo con color.

const CLASES = {
    exito: 'alert-success',
    aviso: 'alert-info',
    error: 'alert-danger',
};

const ICONOS = {
    exito: 'bi-check-circle',
    aviso: 'bi-info-circle',
    error: 'bi-exclamation-triangle',
};

// El lugar de los avisos: el de la página si lo tiene; si no, arriba del contenido.
function zonaDeAvisos() {
    let zona = document.getElementById('avisosApi');

    if (!zona) {
        zona = document.createElement('div');
        zona.id = 'avisosApi';
        zona.setAttribute('aria-live', 'polite');
        (document.querySelector('main') ?? document.body).prepend(zona);
    }

    return zona;
}

// Arma un aviso de Bootstrap con su texto, su ícono y, si se pide, un botón de acción.
function armarAviso(tipo, mensaje, accion = null) {
    const aviso = document.createElement('div');
    aviso.className = `alert ${CLASES[tipo]} alert-dismissible fade show d-flex align-items-center gap-2`;
    aviso.setAttribute('role', tipo === 'error' ? 'alert' : 'status');

    const icono = document.createElement('i');
    icono.className = `bi ${ICONOS[tipo]}`;
    icono.setAttribute('aria-hidden', 'true');

    const texto = document.createElement('span');
    texto.className = 'flex-grow-1';
    texto.textContent = mensaje;

    aviso.append(icono, texto);

    if (accion) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-sm btn-outline-dark';
        boton.textContent = accion.texto;
        boton.addEventListener('click', accion.alHacer);
        aviso.append(boton);
    }

    const cerrar = document.createElement('button');
    cerrar.type = 'button';
    cerrar.className = 'btn-close';
    cerrar.dataset.bsDismiss = 'alert';
    cerrar.setAttribute('aria-label', 'Cerrar');
    aviso.append(cerrar);

    return aviso;
}

/** Muestra un aviso: `exito` (completado), `aviso` o `error`. */
export function avisar(tipo, mensaje) {
    zonaDeAvisos().prepend(armarAviso(tipo, mensaje));
}

/** Completado: el mensaje que devolvió el servidor. */
export function completado(mensaje) {
    if (mensaje) avisar('exito', mensaje);
}

/** Carga: bloquea el botón y lo marca ocupado; devuelve con qué liberarlo. Un envío a la vez. */
export function cargando(boton) {
    if (!boton) return () => {};

    const texto = boton.innerHTML;
    boton.disabled = true;
    boton.setAttribute('aria-busy', 'true');
    boton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Un momento…';

    return () => {
        boton.disabled = false;
        boton.removeAttribute('aria-busy');
        boton.innerHTML = texto;
    };
}

/** Sin resultados: reemplaza el contenido del contenedor por el texto que se le pase. */
export function sinResultados(contenedor, texto = 'No hay nada para mostrar.') {
    const vacio = document.createElement('p');
    vacio.className = 'text-body-secondary text-center my-4';
    vacio.textContent = texto;
    contenedor.replaceChildren(vacio);
}

// Escribe aria-describedby, o lo saca si queda vacío.
function describir(control, ids) {
    const limpio = ids.trim().replace(/\s+/g, ' ');

    if (limpio === '') control.removeAttribute('aria-describedby');
    else control.setAttribute('aria-describedby', limpio);
}

/**
 * Un aviso que pertenece a un formulario: va a su `[data-errores-formulario]` si lo tiene (una
 * ventana tapa los avisos de la página), y si no, a los de la página.
 */
export function avisarEnFormulario(formulario, tipo, mensaje) {
    const lugar = formulario?.querySelector('[data-errores-formulario]');

    if (!lugar) {
        avisar(tipo, mensaje);
        return;
    }

    lugar.textContent = mensaje;
    lugar.hidden = mensaje === '';
}

/**
 * Error de validación: cada mensaje en su campo, anunciado por aria-describedby, y el foco en el
 * primero. Los que no tienen un campo visible van arriba del formulario.
 */
export function marcarErrores(formulario, errores) {
    limpiarErrores(formulario);

    const sueltos = [];
    let primero = null;

    Object.entries(errores ?? {}).forEach(([campo, mensajes]) => {
        const mensaje = String([].concat(mensajes)[0] ?? '');
        const control = formulario.elements.namedItem(campo);

        if (!(control instanceof HTMLElement) || control.type === 'hidden') {
            sueltos.push(mensaje);
            return;
        }

        const detalle = document.createElement('div');
        detalle.id = `${control.id || campo}-error`;
        detalle.className = 'invalid-feedback js-error-api';
        detalle.dataset.errorDe = campo;
        detalle.textContent = mensaje;

        // Dentro del grupo si el control está en uno (el botón de ver la contraseña), para que
        // Bootstrap lo muestre como hermano del control marcado.
        const grupo = control.closest('.input-group');
        if (grupo) grupo.append(detalle);
        else control.insertAdjacentElement('afterend', detalle);

        control.classList.add('is-invalid');
        control.dataset.describeBase ??= control.getAttribute('aria-describedby') ?? '';
        describir(control, `${control.dataset.describeBase} ${detalle.id}`);

        primero ??= control;
    });

    if (sueltos.length) avisarEnFormulario(formulario, 'error', sueltos.join(' '));
    primero?.focus();
}

/** Saca las marcas de la validación anterior y deja cada campo como se describía de entrada. */
export function limpiarErrores(formulario) {
    formulario.querySelectorAll('.js-error-api').forEach((d) => d.remove());
    formulario.querySelectorAll('.is-invalid').forEach((control) => {
        control.classList.remove('is-invalid');
        if (control.dataset.describeBase !== undefined) describir(control, control.dataset.describeBase);
    });

    const lugar = formulario.querySelector('[data-errores-formulario]');
    if (lugar) {
        lugar.textContent = '';
        lugar.hidden = true;
    }
}

/** Sin conexión: lo dice y espera la decisión. Resuelve `true` si se reintenta, `false` si se cierra. */
export function esperarReintento(mensaje) {
    return new Promise((resolver) => {
        let decidido = false;
        const aviso = armarAviso('error', mensaje, {
            texto: 'Reintentar',
            alHacer: () => {
                decidido = true;
                aviso.remove();
                resolver(true);
            },
        });

        aviso.addEventListener('closed.bs.alert', () => {
            if (!decidido) resolver(false);
        });

        zonaDeAvisos().prepend(aviso);
    });
}

/** Sesión vencida o terminada: se pide el ingreso de nuevo, diciendo por qué. */
export function pedirIngreso(motivo) {
    window.location.assign(`/login?sesion=${encodeURIComponent(motivo)}`);
}

const PENDIENTE = 'aviso-pendiente';

/**
 * Completado, pero en la pantalla siguiente: una alta que vuelve al listado. Si el navegador no
 * deja guardar (ventana privada), el aviso se pierde; la operación ya se hizo igual.
 */
export function completadoAlVolver(mensaje, destino) {
    try {
        if (mensaje) window.sessionStorage.setItem(PENDIENTE, mensaje);
    } catch {
        // Sin almacenamiento: se navega igual.
    }

    window.location.assign(destino);
}

/** Muestra el aviso que dejó la pantalla anterior, una sola vez. */
export function mostrarAvisoPendiente() {
    let mensaje = null;

    try {
        mensaje = window.sessionStorage.getItem(PENDIENTE);
        window.sessionStorage.removeItem(PENDIENTE);
    } catch {
        return;
    }

    completado(mensaje);
}
