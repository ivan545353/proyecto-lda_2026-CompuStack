import Chart from 'chart.js/auto';

/**
 * El gráfico del panel general: facturado por día.
 *
 * Los números llegan ya agregados desde el servidor y acá sólo se dibujan: un gráfico
 * que recibiera filas y las contara en el navegador sería A-26 otra vez con otra ropa.
 *
 * Mejora progresiva: sin este archivo la tabla plegada debajo del gráfico muestra
 * exactamente los mismos números, así que no se pierde ningún dato.
 */
export function iniciarGraficosDelPanel(raiz = document) {
    const dias = leerJson('facturadoPorDia');

    // El color sale de la variable del sistema para no tener dos paletas.
    const acento = getComputedStyle(document.documentElement)
        .getPropertyValue('--cs-acento').trim() || '#1a1a1a';

    const pesos = new Intl.NumberFormat('es-AR', {
        style: 'currency',
        currency: 'ARS',
        maximumFractionDigits: 0,
    });

    const ejeDePesos = {
        ticks: { callback: (valor) => pesos.format(valor) },
        grid: { color: 'rgba(0, 0, 0, .06)' },
    };

    const sinLeyenda = {
        responsive: true,
        // Relación fija en lugar de una altura en píxeles: así el lienzo acompaña el
        // ancho de la tarjeta sin un style suelto en la vista.
        aspectRatio: 2,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: { label: (ctx) => pesos.format(ctx.parsed.y) },
            },
        },
    };

    const lienzoDias = raiz.querySelector('[data-grafico-dias]');

    if (lienzoDias && dias.length) {
        new Chart(lienzoDias, {
            type: 'line',
            data: {
                labels: dias.map((fila) => fila.dia),
                datasets: [{
                    data: dias.map((fila) => fila.facturado),
                    borderColor: acento,
                    backgroundColor: 'rgba(26, 26, 26, .08)',
                    fill: true,
                    tension: .25,
                    pointRadius: 3,
                }],
            },
            options: { ...sinLeyenda, scales: { y: { ...ejeDePesos, beginAtZero: true } } },
        });
    }
}

function leerJson(id) {
    try {
        return JSON.parse(document.getElementById(id)?.textContent ?? '[]');
    } catch {
        return [];
    }
}