<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Topes de descuento — hallazgo A-12
    |--------------------------------------------------------------------------
    |
    | En el sistema original `descuentoPorcentaje` lo fijaba el cliente y se
    | validaba sólo que estuviera entre 0 y 100: cualquier vendedor podía cargar
    | el 100 % de descuento, y en el dump hay una venta al 50 %. No había tope
    | por perfil ni flujo de autorización.
    |
    | Acá el tope depende del permiso `venta.autorizar_descuento`: sin él rige el
    | general, con él el autorizado. Los dos son porcentajes sobre el subtotal.
    |
    | Vive en configuración y no escrito en el código porque es un parámetro del
    | negocio, no una constante del programa: el dueño puede querer moverlo sin
    | que nadie recompile nada, que es el mismo argumento por el que los permisos
    | están en la base. Y no sale de `.env` porque no cambia entre entornos: el
    | tope es el mismo en desarrollo y en producción, y un valor que vive en un
    | `.env` que nadie define se vuelve invisible. Si algún día tuviera que variar
    | por entorno, es cambiar el literal por un `env()`.
    |
    | El 100 % no se alcanza por ningún camino: el tope autorizado es el techo y
    | el servicio lo valida además del Form Request.
    |
    */

    'descuento' => [
        'tope_general'    => 10,
        'tope_autorizado' => 30,
    ],

];