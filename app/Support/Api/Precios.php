<?php

namespace App\Support\Api;

use App\Models\Producto;
use App\Support\Importe;

/**
 * La clave `precios` de un producto (docs/api.md §5), en un solo lugar para el listado y la
 * ficha. Quién la ve lo decide el Resource; acá sólo se arma la forma.
 *
 * Métodos:
 *   de()  los dos precios del producto, cada uno con su texto
 */
final class Precios
{
    /** @return array{contado: array{importe: string, texto: string}, lista: array{importe: string, texto: string}} */
    public static function de(Producto $producto): array
    {
        return [
            'contado' => ['importe' => Importe::json($producto->precio_contado), 'texto' => TextosTienda::precio('contado')],
            'lista'   => ['importe' => Importe::json($producto->precio_lista), 'texto' => TextosTienda::precio('lista')],
        ];
    }
}
