<?php

/*
|--------------------------------------------------------------------------
| Parámetros de la tienda (API v1)
|--------------------------------------------------------------------------
|
| Todas las claves de la Etapa 2 viven acá desde el tramo A, aunque algunas las
| consuman tramos posteriores: así `docs/api.md` y el código nombran lo mismo
| desde el principio. Son parámetros del negocio, no constantes del programa
| (mismo criterio que config/venta.php): el dueño puede moverlos sin tocar código.
|
| Sólo `pagos.simulador` sale de `.env`, porque es lo único que cambia entre
| entornos: en la demostración está encendido y en producción no.
|
| Los valores marcados «a revisar» son de partida: el plan fija la clave pero no
| el número. Se confirman con el catálogo de demostración (plan §14.3, tramo C).
|
*/
return [

    // Sección «novedades»: productos creados dentro de la ventana, los más recientes primero.
    'novedades' => [
        'dias' => 30,   // a revisar
        'tope' => 10,   // a revisar
    ],

    // Niveles de disponibilidad. Nunca se publica el número de unidades.
    'disponibilidad' => [
        // Con esta cantidad disponible o menos (y más de cero): «Últimas unidades».
        'ultimas_unidades' => 3,   // a revisar
    ],

    // Paginado de todo listado (docs/api.md §6.2). Más que `tope` es 422.
    'paginado' => [
        'por_pagina' => 20,
        'tope'       => 50,
    ],

    // Plazos de las reservas de stock (decisiones D-1, D-9 y D-16).
    'reservas' => [
        'checkout_minutos' => 20,   // reserva al entrar al checkout
        'contado_horas'    => 48,   // pedido de contado: tiene que llegar al mostrador
        'pasarela_minutos' => 20,   // pedido que espera el pago de la pasarela
    ],

    'pagos' => [
        // Habilita el medio «Simulador — no es cobro real» (D-13).
        'simulador'       => env('TIENDA_SIMULADOR', true),
        'intento_minutos' => 20,   // a revisar: vigencia de un intento de pago
    ],

    'envio' => [
        // Costo fijo de envío. Los envíos calculados son de la Etapa 4.
        'costo' => '0.00',   // a revisar
    ],

    'carrito' => [
        'cantidad_maxima' => 10,   // a revisar: por renglón
    ],

    // La descripción breve se deriva de la completa (D-12): las primeras palabras, y nunca más de
    // `caracteres`. 25 palabras en español ocupan unos 140 a 200 caracteres, así que el tope no toca
    // un texto normal; sólo corta el que no tiene espacios (pendiente #83).
    'descripcion_breve' => [
        'palabras'   => 25,    // a revisar
        'caracteres' => 250,
    ],

];
