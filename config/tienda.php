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
        // Vigencia de un intento de pago (Q-1, tramo G): igual que `reservas.pasarela_minutos`, un solo
        // plazo. Si el intento termina después que la reserva, la reserva se estira hasta él (D-9).
        'intento_minutos' => 20,
    ],

    // Vigencia del enlace firmado del comprobante (D-22): lo que tarda el navegador en abrirlo, no más.
    // Vencido, se pide otro desde el detalle del pedido.
    'comprobante_minutos' => 5,

    'envio' => [
        // Si la tienda ofrece envío además del retiro (requisito 5.3). Es el recorte 2 del plan:
        // en `false`, sólo retiro y costo cero. Lo lee el checkout (E) y la confirmación (F).
        'habilitado' => true,
        // Costo fijo de envío (Q-1, tramo F): se suma al total del pedido. Los calculados son de la Etapa 4.
        'costo' => '8000.00',
    ],

    'carrito' => [
        // Por renglón (Q-1, tramo E): alcanza para una compra de hogar o pyme y frena el carrito
        // que acapara stock. Pasarse en la solicitud es 422; sumando sobre el renglón, 409.
        'cantidad_maxima' => 10,
    ],

    // La descripción breve se deriva de la completa (D-12): las primeras palabras, y nunca más de
    // `caracteres`. 25 palabras en español ocupan unos 140 a 200 caracteres, así que el tope no toca
    // un texto normal; sólo corta el que no tiene espacios (pendiente #83).
    'descripcion_breve' => [
        'palabras'   => 25,    // a revisar
        'caracteres' => 250,
    ],

];
