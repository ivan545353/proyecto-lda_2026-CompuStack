<?php

namespace App\Support\Api;

/**
 * Los textos que la API le muestra al cliente: un solo diccionario.
 *
 * La app no traduce ENUM (docs/api.md §6.1): cada estado viaja con su texto, y el
 * texto sale de acá. Si el negocio cambia cómo se llama «pendiente_pago», cambia en
 * un lugar y la app no se entera.
 *
 * No reusa `Venta::ESTADOS`: ése es el vocabulario del mostrador («Pendiente de
 * pago») y éste habla con el cliente («Esperando el pago»). Mismas claves, otra voz.
 *
 * Métodos:
 *   pedido()          texto del estado de un pedido
 *   pago()            texto del estado del pago
 *   disponibilidad()  texto del nivel de disponibilidad
 *   pasarela()        texto de una pasarela
 */
final class TextosTienda
{
    /** Las claves son `ventas.estado`. */
    public const PEDIDO = [
        'presupuesto'       => 'Presupuesto',
        'pendiente_pago'    => 'Esperando el pago',
        'pagada'            => 'Pagado',
        'en_preparacion'    => 'Preparando tu pedido',
        'despachada'        => 'En camino',
        'lista_retiro'      => 'Listo para retirar',
        'entregada_parcial' => 'Entregado en parte',
        'entregada'         => 'Entregado',
        'cancelada'         => 'Cancelado',
        'devuelta_parcial'  => 'Devuelto en parte',
        'devuelta'          => 'Devuelto',
    ];

    /**
     * Estado del pago: es derivado, no una columna (regla 9). El tramo G lo calcula;
     * acá vive lo que dice cada uno. `pendiente` tiene tres lecturas según el medio
     * y el último intento, y las dos que no son la general van aparte.
     */
    public const PAGO = [
        'aprobado'    => 'Pagado',
        'pendiente'   => 'Falta pagar',
        'en_revision' => 'Pago recibido, en revisión',
        'rechazado'   => 'Pago rechazado: podés reintentar',
        'sin_cobrar'  => 'No se cobró',
    ];

    public const PAGO_PENDIENTE_CONTADO  = 'Se paga en el local';
    public const PAGO_PENDIENTE_INTENTO  = 'Esperando la confirmación del pago';

    /** Niveles de disponibilidad: nunca se publica el número de unidades. */
    public const DISPONIBILIDAD = [
        'disponible'       => 'Disponible',
        'ultimas_unidades' => 'Últimas unidades',
        'sin_stock'        => 'Sin stock',
    ];

    /**
     * El simulador se dice como lo que es, en todas partes: afirmar un cobro real
     * que no existe sería lo mismo que registrarlo como `mercadopago` (D-13).
     */
    public const PASARELA = [
        'mercadopago' => 'Mercado Pago',
        'simulador'   => 'Simulador de pagos — no es un cobro real',
    ];

    public static function pedido(string $estado): string
    {
        return self::PEDIDO[$estado] ?? $estado;
    }

    public static function pago(string $estado): string
    {
        return self::PAGO[$estado] ?? $estado;
    }

    public static function disponibilidad(string $nivel): string
    {
        return self::DISPONIBILIDAD[$nivel] ?? $nivel;
    }

    public static function pasarela(string $pasarela): string
    {
        return self::PASARELA[$pasarela] ?? $pasarela;
    }
}
