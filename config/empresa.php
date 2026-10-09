<?php

/*
|--------------------------------------------------------------------------
| Datos de la empresa
|--------------------------------------------------------------------------
|
| Los imprime el PDF del pedido, y los va a reusar el comprobante de venta 
|
| Viven en `.env` y no en una tabla de configuración por dos razones: el modelo de
| datos está cerrado y no hay pantalla que los administre, y son datos del
| entorno —cambian al instalar el sistema en otro lado, no durante su uso—.
|
| Ningún valor real acá: `.env.example` lleva las claves vacías y el `.env` de
| cada instalación las completa. Leer `env()` dentro de un archivo de config es lo
| correcto; hacerlo en cualquier otro lado rompe `config:cache`.
|
*/

return [
    'nombre'    => env('EMPRESA_NOMBRE', 'CompuStack'),
    'cuit'      => env('EMPRESA_CUIT'),
    'direccion' => env('EMPRESA_DIRECCION'),
    'localidad' => env('EMPRESA_LOCALIDAD'),
    'telefono'  => env('EMPRESA_TELEFONO'),
    'email'     => env('EMPRESA_EMAIL'),
];