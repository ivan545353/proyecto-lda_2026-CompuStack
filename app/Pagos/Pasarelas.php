<?php

namespace App\Pagos;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Elige la pasarela por su clave y dice cuáles están habilitadas, desde la configuración. Que una
 * esté habilitada es un hecho verificable: el simulador por su llave, Mercado Pago por su credencial.
 */
final class Pasarelas
{
    private const CLASES = [
        'simulador'   => PasarelaSimulador::class,
        'mercadopago' => PasarelaMercadoPago::class,
    ];

    public function __construct(private Container $contenedor) {}

    /** @throws InvalidArgumentException  la clave no es una pasarela: es un error de programa */
    public function para(string $pasarela): Pasarela
    {
        if (! isset(self::CLASES[$pasarela])) {
            throw new InvalidArgumentException("No hay una pasarela «{$pasarela}».");
        }

        return $this->contenedor->make(self::CLASES[$pasarela]);
    }

    public function habilitada(string $pasarela): bool
    {
        return match ($pasarela) {
            'simulador'   => (bool) config('tienda.pagos.simulador'),
            // Sin credencial no hay a quién preguntar: ofrecerla sería afirmar un cobro imposible.
            'mercadopago' => filled(config('services.mercadopago.access_token')),
            default       => false,
        };
    }

    /** @return list<string>  Mercado Pago primero, como la pantalla de pago */
    public function habilitadas(): array
    {
        return array_values(array_filter(['mercadopago', 'simulador'], fn (string $p) => $this->habilitada($p)));
    }
}
