import { Modal } from 'bootstrap';
import { api } from '../api/cliente';
import { cargando } from '../api/estados';

// Un listado migrado (tramo D) imprime por la API: pide el enlace firmado con la credencial y el
// navegador lo abre sin ella (D-22). La descarga no cambia de página.
async function imprimirPorApi(ruta, parametros, boton) {
    const liberar = cargando(boton);

    try {
        const { datos } = await api.get(`${ruta}?${parametros}`);
        window.location.assign(datos.url);
    } catch {
        // El módulo central ya lo mostró: una opción mal pedida es 422.
    } finally {
        liberar();
    }
}

/**
 * El diálogo «Opciones de impresión»: muestra el botón que lo abre y apaga lo que no aplica.
 */
export function iniciarOpcionesDeImpresion(raiz = document) {
    raiz.querySelectorAll('[data-impresion-abre]').forEach((boton) => {
        boton.hidden = false;
    });

    // El botón de un clic: los filtros de la pantalla, sin la página. El papel no pagina.
    raiz.querySelectorAll('[data-imprimir-api]:not(form)').forEach((boton) => {
        boton.addEventListener('click', () => {
            const parametros = new URLSearchParams(window.location.search);
            parametros.delete('pagina');
            imprimirPorApi(boton.dataset.imprimirApi, parametros, boton);
        });
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
        formulario.addEventListener('submit', (evento) => {
            if (formulario.dataset.imprimirApi) {
                evento.preventDefault();
                // Lo apagado no viaja: FormData deja afuera el papel de una planilla.
                imprimirPorApi(formulario.dataset.imprimirApi, new URLSearchParams(new FormData(formulario)), enviar);
            }

            if (ventana) Modal.getInstance(ventana)?.hide();
        });

        refrescar();
    });
}