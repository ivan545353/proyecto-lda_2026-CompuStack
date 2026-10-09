/**
 * Pantalla de recepción de mercadería.
 *
 * Mejora progresiva: sin este archivo el formulario funciona igual, se carga a mano
 * línea por línea y el servidor valida exactamente lo mismo. Lo que se pierde es el
 * botón de «llegó todo» y ver la consecuencia antes de guardar.
 *
 * Es el mismo recurso que la pantalla de ajuste de inventario: mostrar el resultado
 * mientras se escribe. Un 30 escrito en lugar de un 3 se delata solo cuando la
 * columna «quedarían» pasa a decir «sobran 27».
 */
export function iniciarRecepcion(raiz = document) {
    const form = raiz.querySelector('[data-recepcion]');
    if (!form) return;

    // El botón de «llegó todo» vive fuera del <form>, en la cabecera de la tarjeta.
    const contenedor = form.closest('.card') ?? raiz;

    const totalEl = form.querySelector('[data-total-recibido]');
    const botonTodo = contenedor.querySelector('[data-recibir-todo]');
    const filas = () => [...form.querySelectorAll('tr[data-linea]')];

    const refrescar = () => {
        let total = 0;

        filas().forEach((fila) => {
            const pendiente = Number(fila.dataset.pendiente ?? 0);
            const campo = fila.querySelector('[data-recibidas]');
            const recibidas = Math.max(0, Number(campo?.value ?? 0) || 0);

            total += recibidas;

            const quedan = fila.querySelector('[data-quedan]');
            if (!quedan) return;

            const resto = pendiente - recibidas;

            // Si sobra, se avisa acá, pero la barrera es el servidor: rechaza la
            // recepción entera explicando qué hacer con el excedente.
            quedan.textContent = resto < 0 ? `sobran ${Math.abs(resto)}` : String(resto);
            quedan.classList.toggle('text-danger', resto < 0);
            quedan.classList.toggle('fw-semibold', resto < 0);
        });

        if (totalEl) {
            totalEl.textContent = total === 0
                ? 'Todavía no cargaste ninguna unidad.'
                : `Se van a registrar ${total} unidad${total === 1 ? '' : 'es'} y a mover el stock.`;
        }
    };

    botonTodo?.addEventListener('click', () => {
        filas().forEach((fila) => {
            const campo = fila.querySelector('[data-recibidas]');

            if (campo && !campo.disabled) campo.value = fila.dataset.pendiente;
        });

        refrescar();
    });

    form.addEventListener('input', refrescar);

    refrescar();
}