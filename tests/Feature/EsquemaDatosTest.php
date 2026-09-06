<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Estas pruebas verifican el esquema, no el comportamiento. Consultan
 * information_schema, así que exigen MariaDB: el phpunit.xml de la Fase 1
 * apunta a la conexión mariadb, no a sqlite en memoria.
 */

const TABLAS_DOMINIO = [
    'roles', 'permisos', 'rol_permiso', 'users',
    'empleados', 'clientes', 'direcciones',
    'categorias', 'marcas', 'proveedores', 'productos', 'movimientos_stock',
    'ordenes_compra', 'orden_compra_lineas',
    'ventas', 'venta_lineas', 'pagos',
];

function tipoDeColumna(string $tabla, string $columna): string
{
    $fila = DB::selectOne(
        'SELECT COLUMN_TYPE AS tipo
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB::getDatabaseName(), $tabla, $columna],
    );

    expect($fila)->not->toBeNull("No existe {$tabla}.{$columna}");

    return $fila->tipo;
}

test('existen las diecisiete tablas de la etapa 1', function () {
    foreach (TABLAS_DOMINIO as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue("Falta la tabla {$tabla}");
    }
});

test('ninguna columna usa punto flotante', function () {
    // Hallazgo A-17: productos.precio era float(12,2) en el sistema original y
    // acumulaba error de redondeo. Todo importe va en decimal.
    $flotantes = DB::select(
        "SELECT TABLE_NAME AS tabla, COLUMN_NAME AS columna
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('float', 'double', 'real')",
        [DB::getDatabaseName()],
    );

    expect($flotantes)->toBeEmpty(
        'Columnas en punto flotante: '.collect($flotantes)->map(
            fn ($c) => "{$c->tabla}.{$c->columna}"
        )->implode(', ')
    );
});

test('el enum de estado de venta declara los diez valores', function () {
    // Cambiar un ENUM reescribe la tabla entera. Los estados de la Etapa 2 ya
    // están declarados aunque todavía no se usen.
    $tipo = tipoDeColumna('ventas', 'estado');

    foreach ([
        'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
        'despachada', 'lista_retiro', 'entregada', 'cancelada',
        'devuelta_parcial', 'devuelta',
    ] as $estado) {
        expect($tipo)->toContain("'{$estado}'");
    }
});

test('el enum de movimientos de stock incluye la liberacion de reserva', function () {
    $tipo = tipoDeColumna('movimientos_stock', 'tipo');

    foreach (['venta', 'devolucion', 'compra', 'ajuste', 'reserva_liberada'] as $valor) {
        expect($tipo)->toContain("'{$valor}'");
    }
});

test('pagos tiene las columnas de mercado pago con idempotencia', function () {
    // mp_payment_id con índice único es lo que impide que un reintento de
    // webhook acredite el mismo pago dos veces (hallazgo C-10).
    foreach (['comision', 'neto_acreditado', 'cuotas', 'mp_payment_id', 'mp_status'] as $columna) {
        expect(Schema::hasColumn('pagos', $columna))->toBeTrue("Falta pagos.{$columna}");
    }

    $indices = DB::select(
        'SELECT INDEX_NAME AS nombre, NON_UNIQUE AS no_unico
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [DB::getDatabaseName(), 'pagos', 'mp_payment_id'],
    );

    expect(collect($indices)->contains(fn ($i) => (int) $i->no_unico === 0))->toBeTrue(
        'mp_payment_id no tiene índice único'
    );
});

test('toda tabla del dominio tiene marcas de tiempo', function () {
    // Hallazgo M-19: ninguna tabla del original tenía created_at / updated_at,
    // lo que hacía imposible el panel por período y cualquier auditoría.
    $sinTimestamps = collect(TABLAS_DOMINIO)
        ->reject(fn ($t) => $t === 'rol_permiso')   // pivote puro: no lleva
        ->reject(fn ($t) => Schema::hasColumn($t, 'created_at') && Schema::hasColumn($t, 'updated_at'))
        ->values();

    expect($sinTimestamps->all())->toBeEmpty();
});

test('las lineas de venta congelan precio, alicuota y costo', function () {
    foreach (['precio_unitario', 'alicuota_iva', 'costo_unitario', 'cantidad_devuelta'] as $columna) {
        expect(Schema::hasColumn('venta_lineas', $columna))->toBeTrue("Falta venta_lineas.{$columna}");
    }
});
