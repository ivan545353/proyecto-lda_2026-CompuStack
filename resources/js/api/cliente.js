// El módulo central de comunicación con la API (consigna §8): dirección, credencial, cabeceras,
// lectura del sobre y tratamiento de errores, en un solo lado. Ninguna pantalla arma su fetch.
//
// La credencial vive en la cookie `credencial` (D-30): la navegación la manda sola para servir las
// pantallas (AutenticarConCredencial) y este módulo la manda como Bearer a la API, que no lee
// cookies. Es el único lugar que la lee o la escribe.

import { avisar, avisarEnFormulario, esperarReintento, marcarErrores, pedirIngreso } from './estados';

const BASE = '/api/v1';
const COOKIE = 'credencial';

const SIN_CONEXION = 'No pudimos conectarnos con el servidor. Revisá la conexión y reintentá.';
const SIN_PERMISO = 'No tenés permiso para hacer esto.';

/** Un error de la API ya tratado: la pantalla sólo decide qué hacer con lo suyo. */
export class ErrorDeApi extends Error {
    constructor(estado, codigo, mensaje, errores = {}, cuerpo = null) {
        super(mensaje);
        this.estado = estado;
        this.codigo = codigo;
        this.errores = errores;
        this.cuerpo = cuerpo;
    }
}

// Guarda la credencial que devolvió el ingreso. Muere al cerrar el navegador: no lleva vencimiento.
function guardarCredencial(credencial) {
    const segura = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${encodeURIComponent(credencial)}; Path=/; SameSite=Strict${segura}`;
}

// Olvida la credencial de este navegador.
function olvidarCredencial() {
    document.cookie = `${COOKIE}=; Path=/; Max-Age=0; SameSite=Strict`;
}

function leerCredencial() {
    const par = document.cookie.split('; ').find((c) => c.startsWith(`${COOKIE}=`));

    return par ? decodeURIComponent(par.slice(COOKIE.length + 1)) : null;
}

// Todo pedido va a la API, con la credencial como Bearer. Desde el tramo I no queda ninguna ruta web
// de escritura, así que tampoco el token CSRF de la página.
function cabeceras(conCuerpoJson) {
    const lista = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (conCuerpoJson) lista['Content-Type'] = 'application/json';

    const credencial = leerCredencial();
    if (credencial) lista.Authorization = `Bearer ${credencial}`;

    return lista;
}

async function leerCuerpo(respuesta) {
    if (respuesta.status === 204) return null;

    try {
        return await respuesta.json();
    } catch {
        return null;
    }
}

// El tratamiento de cada error, una vez: 401 pide el ingreso, 403 avisa, 422 marca los campos,
// 409 y el resto muestran el mensaje del servidor.
function tratar(respuesta, cuerpo, formulario) {
    const codigo = cuerpo?.codigo ?? null;
    const errores = cuerpo?.errores ?? {};
    const mensaje = cuerpo?.mensaje ?? 'Algo falló del lado del servidor. Probá de nuevo en un rato.';
    const error = new ErrorDeApi(respuesta.status, codigo, mensaje, errores, cuerpo);

    switch (respuesta.status) {
        case 401:
            olvidarCredencial();
            pedirIngreso(codigo === 'sesion_expirada' ? 'vencida' : 'terminada');
            break;
        case 403:
            avisarEnFormulario(formulario, 'error', cuerpo?.mensaje ?? SIN_PERMISO);
            break;
        case 422:
            if (formulario) marcarErrores(formulario, errores);
            else avisar('error', mensaje);
            break;
        default:
            avisarEnFormulario(formulario, 'error', mensaje);
    }

    return error;
}

/**
 * Hace un pedido y devuelve el sobre (`datos`, `mensaje` y, en un listado, el paginado).
 * Opciones: `metodo`, `datos` (va como JSON, o tal cual si es FormData), `formulario` (donde marcar
 * los errores por campo) y `senal` (para cancelar).
 */
export async function pedir(ruta, { metodo = 'GET', datos = null, formulario = null, senal = null } = {}) {
    const esFormulario = datos instanceof FormData;
    const opciones = {
        method: metodo,
        credentials: 'same-origin',
        signal: senal,
        headers: cabeceras(datos !== null && !esFormulario),
        body: datos === null ? undefined : esFormulario ? datos : JSON.stringify(datos),
    };

    let respuesta;

    try {
        respuesta = await fetch(`${BASE}${ruta}`, opciones);
    } catch (falla) {
        // Cancelado por la pantalla (una búsqueda que se reemplazó): no es un error que mostrar.
        if (falla?.name === 'AbortError') throw falla;

        if (await esperarReintento(SIN_CONEXION)) {
            return pedir(ruta, { metodo, datos, formulario, senal });
        }

        throw new ErrorDeApi(0, 'sin_conexion', SIN_CONEXION);
    }

    const cuerpo = await leerCuerpo(respuesta);

    if (!respuesta.ok) throw tratar(respuesta, cuerpo, formulario);

    return cuerpo;
}

const SIN_ACCESO = 'Esta cuenta no tiene acceso al sistema de gestión.';

/**
 * Inicia sesión y guarda la credencial. Devuelve `true` si la cuenta puede entrar a la administración.
 * Que sea de gestión lo vuelve a decidir el servidor en cada pantalla: esto sólo evita una vuelta.
 */
export async function ingresar(email, password, formulario) {
    const { datos } = await api.post('/sesion', { email, password }, { formulario });
    guardarCredencial(datos.credencial);

    const yo = await api.get('/yo');

    if (yo.datos.rol.ambito !== 'gestion') {
        await salir(false);
        marcarErrores(formulario, { email: [SIN_ACCESO] });

        return false;
    }

    return true;
}

/**
 * Cambia la contraseña propia. El servidor revoca todas las credenciales de la cuenta, también esta, y
 * devuelve una nueva (HL-132): se guarda en su lugar para que el cambio no expulse a quien lo hizo.
 */
export async function cambiarContrasena(datos, formulario) {
    const respuesta = await api.put('/yo/contrasena', datos, { formulario });
    guardarCredencial(respuesta.datos.credencial);

    return respuesta;
}

/** Cierra la sesión: la credencial deja de servir en el servidor y se olvida acá. */
export async function salir(irAlIngreso = true) {
    try {
        await api.delete('/sesion');
    } finally {
        olvidarCredencial();
        if (irAlIngreso) window.location.assign('/login');
    }
}

export const api = {
    get: (ruta, opciones = {}) => pedir(ruta, { ...opciones, metodo: 'GET' }),
    post: (ruta, datos, opciones = {}) => pedir(ruta, { ...opciones, metodo: 'POST', datos }),
    put: (ruta, datos, opciones = {}) => pedir(ruta, { ...opciones, metodo: 'PUT', datos }),
    patch: (ruta, datos, opciones = {}) => pedir(ruta, { ...opciones, metodo: 'PATCH', datos }),
    delete: (ruta, opciones = {}) => pedir(ruta, { ...opciones, metodo: 'DELETE' }),
};
