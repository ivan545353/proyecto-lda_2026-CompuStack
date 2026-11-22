// Los mismos formatos que App\Support\Importe, para las pantallas que pintan lo que trae la API: el JSON
// trae cadenas con punto y sin miles (regla 13), y la pantalla sólo formatea.

const conDecimales = (decimales) => new Intl.NumberFormat('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
const dosDecimales = conDecimales(2);

/** "1500.5" → "$ 1.500,50". */
export const pesos = (importe) => `$ ${dosDecimales.format(Number(importe ?? 0))}`;

/** "1500.5" → "1.500,50", para el value de un campo. */
export const paraFormulario = (importe) => (importe === null || importe === undefined || importe === '' ? '' : dosDecimales.format(Number(importe)));

/** Un número sin ceros sobrantes: "2.500" → "2,5". Para porcentajes y multiplicadores. */
export const numero = (valor, decimales = 3) => (valor === null || valor === undefined || valor === ''
    ? ''
    : new Intl.NumberFormat('es-AR', { maximumFractionDigits: decimales }).format(Number(valor)));

const conHora = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/** "2026-11-21T13:05:00Z" → "21/11/2026, 10:05", en la hora del navegador. Vacío si no hay fecha. */
export const fechaHora = (iso) => (iso ? conHora.format(new Date(iso)) : '');
