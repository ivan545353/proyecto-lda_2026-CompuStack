import { Modal } from 'bootstrap';

/**
 * El diálogo «Opciones de impresión»: muestra el botón que lo abre y apaga lo que no aplica.
 */
export function iniciarOpcionesDeImpresion(raiz = document) {
    raiz.querySelectorAll('[data-impresion-abre]').forEach((boton) => {
        boton.hidden = false;
    });

    raiz.querySelectorAll('form[data-impresion]').forEach((formulario) => {
        const papel = formulario.querySelector('[data-impresion-papel]');
        const sinPapel = formulario.querySelector('[data-impresion-sin-papel]');
        const agrupar = formulario.querySelector('select[name="agrupar"]');
        const salto = formulario.querySelector('input[name="salto"]');
        const columnas = [...formulario.querySelectorAll('input[name="columnas[]"]')];
        const sinColumnas = formulario.querySelector('[data-impresion-sin-columnas]');
        const enviar = formulario.querySelector('button[type="submit"]');
        const ventana = formulario.closest('.modal');

        const refrescar = () => {
            const esPlanilla = formulario.querySelector('input[name="formato"]:checked')?.value === 'csv';

            // Un fieldset apagado no manda sus campos: la planilla no recibe opciones de la hoja.
            if (papel) papel.disabled = esPlanilla;
            if (sinPapel) sinPapel.textContent = esPlanilla ? sinPapel.dataset.impresionSinPapel : '';

            if (salto) {
                const sinGrupo = !agrupar || agrupar.value === '';
                salto.disabled = sinGrupo;
                if (sinGrupo) salto.checked = false;
            }

            const ninguna = columnas.length > 0 && !columnas.some((casilla) => casilla.checked);
            if (sinColumnas) sinColumnas.textContent = ninguna ? sinColumnas.dataset.impresionSinColumnas : '';
            if (enviar) enviar.disabled = ninguna;
        };

        formulario.addEventListener('change', refrescar);

        // La descarga no cambia de página: la ventana se cierra para que la pantalla vuelva a estar a mano.
        formulario.addEventListener('submit', () => {
            if (ventana) Modal.getInstance(ventana)?.hide();
        });

        refrescar();
    });
}