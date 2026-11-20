// Una selección de filas que se manda en un formulario GET (el ajuste de precios, el pedido de
// sugeridos): la casilla «todas», el contador y el botón que se apaga sin nada marcado.
//
// Las casillas pueden estar dentro del formulario o afuera con `form="<id>"`. Se buscan en cada
// cambio y no una vez: en un listado por la API (tramo D) las filas llegan después y se reemplazan
// al recargar. Un formulario con `data-seleccion-diferida` lo arranca su pantalla cuando hay filas.

const casillasDe = (formulario) => [...new Set([
    ...formulario.querySelectorAll('input[type="checkbox"][name]'),
    ...(formulario.id ? document.querySelectorAll(`input[type="checkbox"][name][form="${formulario.id}"]`) : []),
])];

/** Arranca la selección de un formulario; si ya estaba arrancada, la vuelve a contar. */
export function iniciarSeleccion(formulario) {
    if (formulario.refrescarSeleccion) {
        formulario.refrescarSeleccion();

        return;
    }

    const todas = formulario.id ? document.querySelector(`[data-seleccion-todas="${formulario.id}"]`) : null;
    const boton = formulario.querySelector('button[type="submit"]');
    const contador = formulario.querySelector('[data-seleccion-contador]');
    const sinMarcar = contador?.textContent.trim() ?? '';
    const conMarcadas = contador?.dataset.seleccionMarcadas ?? '';

    const refrescar = () => {
        const casillas = casillasDe(formulario);
        const marcadas = casillas.filter((casilla) => casilla.checked).length;

        if (todas) {
            todas.checked = marcadas > 0 && marcadas === casillas.length;
            todas.indeterminate = marcadas > 0 && marcadas < casillas.length;
        }

        // El servidor lo rinde habilitado porque sin JS tiene que poder usarse; que se apague con
        // la selección vacía es comodidad, no el estado inicial.
        if (boton) boton.disabled = marcadas === 0;

        if (contador) {
            contador.textContent = marcadas === 0 || conMarcadas === ''
                ? sinMarcar
                : conMarcadas.replace('{n}', marcadas);
        }
    };

    todas?.addEventListener('change', () => {
        casillasDe(formulario).forEach((casilla) => (casilla.checked = todas.checked));
        refrescar();
    });

    document.addEventListener('change', (evento) => {
        if (evento.target !== todas && casillasDe(formulario).includes(evento.target)) refrescar();
    });

    formulario.refrescarSeleccion = refrescar;
    refrescar();
}

/** Los formularios de selección que ya traen sus filas en el HTML. */
export function iniciarSelecciones() {
    document.querySelectorAll('form[data-seleccion]:not([data-seleccion-diferida])').forEach((formulario) => {
        if (casillasDe(formulario).length > 0) iniciarSeleccion(formulario);
    });
}
