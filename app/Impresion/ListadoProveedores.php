<?php

namespace App\Impresion;

use App\Models\Proveedor;

/**
 * El padrón de proveedores, con lo mismo que muestra la pantalla y nada más.
 */
final class ListadoProveedores extends Listado
{
    private const ESTADOS = ['activos' => 'Activos', 'inactivos' => 'Inactivos'];

    public function titulo(): string
    {
        return 'Proveedores';
    }

    public function archivo(): string
    {
        return 'proveedores';
    }

    protected function sustantivo(): array
    {
        return ['proveedor', 'proveedores'];
    }

    public function conteos(): array
    {
        return ['productos', 'ordenesCompra'];
    }

    /** El teléfono no: el listado no lo muestra, y el correo sólo cuando es por donde se le pide. */
    public function columnas(): array
    {
        return [
            new Columna('proveedor', 'Proveedor', fn (Proveedor $proveedor) => $proveedor->razon_social),
            new Columna('cuit', 'CUIT', fn (Proveedor $proveedor) => $proveedor->cuitFormateado()),
            new Columna('contacto', 'Contacto', fn (Proveedor $proveedor) => $proveedor->contacto),
            new Columna('canal', 'Canal de pedido', fn (Proveedor $proveedor) => $proveedor->canalPedidoTexto()),
            new Columna('pedidos_a', 'Se le pide en', fn (Proveedor $proveedor) => match ($proveedor->canal_pedido) {
                'email'          => $proveedor->email,
                'portal_externo' => $proveedor->portal_url,
                default          => null,
            }, porOmision: false),
            // Cero es «no se cargó»: no es una entrega en el día.
            new Columna('plazo', 'Entrega (días)',
                fn (Proveedor $proveedor) => $proveedor->plazo_entrega_dias > 0 ? $proveedor->plazo_entrega_dias : null,
                Columna::ENTERO, porOmision: false),
            new Columna('productos', 'Productos', fn (Proveedor $proveedor) => $proveedor->productos_count,
                Columna::ENTERO, porOmision: false),
            new Columna('ordenes', 'Órdenes', fn (Proveedor $proveedor) => $proveedor->ordenes_compra_count,
                Columna::ENTERO, porOmision: false),
            new Columna('estado', 'Estado', fn (Proveedor $proveedor) => $proveedor->activo ? 'Activo' : 'Inactivo'),
        ];
    }

    public function filtrosAplicados(array $filtros): array
    {
        return array_filter([
            'Búsqueda'        => $filtros['q'] ?? null,
            'Estado'          => self::ESTADOS[$filtros['estado'] ?? ''] ?? null,
            'Canal de pedido' => Proveedor::CANALES[$filtros['canal'] ?? ''] ?? null,
        ]);
    }
}