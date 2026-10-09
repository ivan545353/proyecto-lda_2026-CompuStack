<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Venta;

uses(RefreshDatabase::class);

/**
 * Estas pruebas verifican el esquema, no el comportamiento. Consultan
 * information_schema, así que exigen MariaDB: el phpunit.xml de la Fase 1
 * apunta a la conexión mariadb, no a sqlite en memoria.
 */

const TABLAS_DOMINIO = [
    'roles', 'permisos', 'rol_permiso', 'users',
    'empleados', 'clientes', 'direcciones',
    'categorias', 'marcas', 'proveedores', 'productos', 'producto_proveedor',
    'movimientos_stock',
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

test('existen las dieciocho tablas de la etapa 1', function () {
    // Eran diecisiete hasta que un producto pasó a tener varios proveedores: la
    // tabla dieciocho es `producto_proveedor`, y la decisión está escrita en
    // docs/modelo-datos.md.
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

test('el enum de estado de venta declara los once valores', function () {
    // Cambiar un ENUM reescribe la tabla entera. Los estados de la Etapa 2 se
    // declararon en la Fase 1 aunque todavía no se usen, y `entregada_parcial` se
    // sumó en la Fase 6 por el mismo motivo: es la única pieza de la venta con
    // faltante que no es barata de agregar después, y la tabla todavía está vacía.
    $tipo = tipoDeColumna('ventas', 'estado');

    foreach ([
        'presupuesto', 'pendiente_pago', 'pagada', 'en_preparacion',
        'despachada', 'lista_retiro', 'entregada_parcial', 'entregada',
        'cancelada', 'devuelta_parcial', 'devuelta',
    ] as $estado) {
        expect($tipo)->toContain("'{$estado}'");
    }
});

test('el enum de estado de venta y la constante del modelo dicen lo mismo', function () {
    // La otra mitad de la afirmación. El test de arriba verifica que los once
    // estén; este, que no haya ninguno de más ni ninguno sin texto de pantalla.
    //
    // Hace falta porque `Venta::ESTADOS` es de donde salen los textos de la
    // máquina de estados, los badges y los selectores: un valor agregado al ENUM
    // sin su texto se mostraría como el valor crudo de la columna, y un texto con
    // la clave mal escrita produciría un estado que ninguna pantalla sabe nombrar.
    // Ninguna de las dos cosas rompe nada, y por eso no la encontraría ningún otro
    // test.
    preg_match_all("/'([^']*)'/", tipoDeColumna('ventas', 'estado'), $coincidencias);

    expect($coincidencias[1])->toEqualCanonicalizing(array_keys(Venta::ESTADOS));
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

test('productos ya no tiene la columna de un solo proveedor', function () {
    // El contract del parallel change, afirmado contra la base y no contra las
    // migraciones. Mientras la columna exista, algo puede volver a escribirla y el
    // sistema tendría dos respuestas para «a quién se le compra este producto».
    expect(Schema::hasColumn('productos', 'proveedor_id'))
        ->toBeFalse('productos.proveedor_id sigue existiendo: el paso 4 del plan no se aplicó')
        ->and(Schema::hasTable('producto_proveedor'))
        ->toBeTrue('falta la pivote que reemplaza a la columna');
});

test('la linea de la orden congela el codigo del proveedor', function () {
    // Nullable: un vínculo puede no tener código, y las líneas escritas antes de la
    // columna tampoco. Null significa «no consta».
    expect(Schema::hasColumn('orden_compra_lineas', 'codigo_proveedor'))->toBeTrue();
});

test('el monto de un pago admite negativos', function () {
    // El contra-asiento de la devolución (A-11) es una fila de pago con monto
    // negativo. La afirmación se hace contra la base y no contra la migración, igual
    // que el resto de este archivo: si alguien declarara la columna `unsigned`, el
    // insert de la reversión fallaría en mitad de la transacción de la devolución y
    // el stock ya repuesto se revertiría con él, así que el usuario vería un error
    // sin entender qué pasó. Es el mismo criterio que `movimientos_stock.cantidad`,
    // que guarda la cantidad con signo.
    expect(tipoDeColumna('pagos', 'monto'))->not->toContain('unsigned');
});