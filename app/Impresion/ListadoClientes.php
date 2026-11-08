<?php

namespace App\Impresion;

use App\Models\Cliente;

/**
 * El padrón de clientes, con lo mismo que muestra la pantalla y nada más.
 */
final class ListadoClientes extends Listado
{
    private const CUENTAS = ['con_cuenta' => 'Con cuenta', 'sin_cuenta' => 'De mostrador'];

    public function titulo(): string
    {
        return 'Clientes';
    }

    public function archivo(): string
    {
        return 'clientes';
    }

    protected function sustantivo(): array
    {
        return ['cliente', 'clientes'];
    }

    /** El correo y el teléfono no: el listado no los muestra, y el papel no muestra más que la pantalla. */
    public function columnas(): array
    {
        return [
            new Columna('cliente', 'Cliente', fn (Cliente $cliente) => $cliente->razon_social),
            new Columna('documento', 'Documento', fn (Cliente $cliente) => $cliente->documento()),
            new Columna('condicion_iva', 'Condición frente al IVA',
                fn (Cliente $cliente) => Cliente::CONDICIONES_IVA[$cliente->condicion_iva] ?? null),
            new Columna('cuenta', 'Cuenta',
                fn (Cliente $cliente) => $cliente->esDeMostrador() ? 'De mostrador' : 'Con cuenta'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        return array_filter([
            'Búsqueda'                => $filtros['q'] ?? null,
            'Condición frente al IVA' => Cliente::CONDICIONES_IVA[$filtros['condicion_iva'] ?? ''] ?? null,
            'Cuenta'                  => self::CUENTAS[$filtros['cuenta'] ?? ''] ?? null,
        ]);
    }
}