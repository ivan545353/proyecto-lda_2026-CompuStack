/**
 * La pantalla de cobro: «Total» por medio, billetes con el vuelto en pantalla y la suma en vivo.
 * Nada de esto viaja ni se guarda: el servidor valida los montos igual que sin JavaScript.
 */

/** Un importe en centavos, con las mismas formas que acepta Importe::normalizar(); NaN si no se entiende. */
export function aCentavos(texto) {
    const limpio = String(texto ?? '').replace(/[$\s]/g, '');

    if (limpio === '') return 0;

    let numero = NaN;

    if (/^\d+(,\d{1,2})?$/.test(limpio)) {
        numero = Number(limpio.replace(',', '.'));
    } else if (/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/.test(limpio)) {
        numero = Number(limpio.replace(/\./g, '').replace(',', '.'));
    } else if (/^\d+\.\d{1,2}$/.test(limpio)) {
        numero = Number(limpio);
    }

    return Math.round(numero * 100);
}

/** Centavos con el formato de Importe::paraFormulario(): 1234567 → «12.345,67». */
export function formatear(centavos) {
    const [enteros, decimales] = (Math.abs(centavos) / 100).toFixed(2).split('.');

    return `${enteros.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimales}`;
}

const pesos = (centavos) => `$ ${formatear(centavos)}`;

export function iniciarCobro(raiz = document) {
    const formulario = raiz.querySelector('form[data-cobro]');

    if (!formulario) return;

    const saldo = Math.round(Number(formulario.dataset.cobroSaldo) * 100);
    const montos = [...formulario.querySelectorAll('[data-cobro-monto]')];
    const efectivo = formulario.querySelector('#monto-efectivo');
    const suma = formulario.querySelector('[data-cobro-suma]');
    const billetes = formulario.querySelector('[data-cobro-billetes]');
    const vuelto = formulario.querySelector('[data-cobro-vuelto]');

    // Mientras el efectivo sea la propuesta del servidor, acompaña a lo que falta;
    // en cuanto el cajero lo toca o usa «Total», es suyo y no se mueve más.
    let efectivoPropuesto = efectivo?.hasAttribute('data-cobro-propuesto') ?? false;
    let recibido = 0;

    const sumarSalvo = (excluido) => montos
        .filter((campo) => campo !== excluido)
        .reduce((total, campo) => total + (aCentavos(campo.value) || 0), 0);

    const actualizarSuma = () => {
        if (!suma) return;

        const valores = montos.map((campo) => ({
            nombre: campo.dataset.cobroMonto,
            centavos: aCentavos(campo.value),
        }));

        if (valores.some((valor) => Number.isNaN(valor.centavos))) {
            suma.textContent = 'Hay un importe que no se entiende: escribilo como 1500,50.';
            return;
        }

        const cargados = valores.filter((valor) => valor.centavos > 0);
        const total = cargados.reduce((acumulado, valor) => acumulado + valor.centavos, 0);

        if (cargados.length === 0) {
            suma.textContent = `Todavía no cargaste ningún importe. Se cobran ${pesos(saldo)}.`;
            return;
        }

        const detalle = cargados.map((valor) => `${valor.nombre} ${pesos(valor.centavos)}`).join(' + ');

        if (total === saldo) {
            suma.textContent = `Se registra: ${detalle}. Coincide con lo que se cobra.`;
        } else if (total < saldo) {
            suma.textContent = `Se registra: ${detalle}. Faltan ${pesos(saldo - total)} para el total.`;
        } else {
            suma.textContent = `Se registra: ${detalle}. Sobran ${pesos(total - saldo)}: el vuelto no se carga.`;
        }
    };

    const actualizarVuelto = () => {
        if (!vuelto || !efectivo) return;

        const cobrado = aCentavos(efectivo.value) || 0;

        if (recibido === 0) {
            vuelto.textContent = 'Tocá los billetes que te dieron y acá aparece el vuelto.';
        } else if (cobrado === 0) {
            vuelto.textContent = `Recibido ${pesos(recibido)}. Cargá el efectivo para calcular el vuelto.`;
        } else if (recibido >= cobrado) {
            vuelto.textContent = `Recibido ${pesos(recibido)}. Vuelto: ${pesos(recibido - cobrado)}.`;
        } else {
            vuelto.textContent = `Recibido ${pesos(recibido)}. Faltan ${pesos(cobrado - recibido)} para el efectivo.`;
        }
    };

    const actualizar = () => {
        actualizarSuma();
        actualizarVuelto();
    };

    montos.forEach((campo) => {
        campo.addEventListener('input', () => {
            if (campo === efectivo) {
                efectivoPropuesto = false;
            } else if (efectivoPropuesto && efectivo) {
                const falta = saldo - sumarSalvo(efectivo);

                efectivo.value = falta > 0 ? formatear(falta) : '';
            }

            actualizar();
        });
    });

    formulario.querySelectorAll('[data-cobro-total]').forEach((boton) => {
        const campo = boton.closest('.input-group')?.querySelector('[data-cobro-monto]');

        if (!campo) return;

        boton.hidden = false;
        boton.addEventListener('click', () => {
            montos.forEach((otro) => {
                otro.value = otro === campo ? formatear(saldo) : '';
            });

            efectivoPropuesto = false;
            campo.focus();
            actualizar();
        });
    });

    if (billetes && efectivo) {
        // Con el mouse, el foco vuelve al efectivo para que Enter cobre y no sume otro billete.
        const devolverFoco = (evento) => {
            if (evento.detail > 0) efectivo.focus();
        };

        billetes.querySelectorAll('[data-billete]').forEach((boton) => {
            boton.addEventListener('click', (evento) => {
                recibido += Number(boton.dataset.billete) * 100;
                actualizarVuelto();
                devolverFoco(evento);
            });
        });

        billetes.querySelector('[data-billetes-borrar]')?.addEventListener('click', (evento) => {
            recibido = 0;
            actualizarVuelto();
            devolverFoco(evento);
        });

        billetes.hidden = false;
    }

    if (suma) suma.hidden = false;

    actualizar();
}