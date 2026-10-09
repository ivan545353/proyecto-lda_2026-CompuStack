<?php

use App\Models\Cliente;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Filtros del listado de ventas
|--------------------------------------------------------------------------
|
| Primer nivel de los dos: acá se prueba que los scopes filtran. Que el parámetro
| de la URL llegue al scope se prueba en VentaModuloTest. A-24 pasó once commits sin
| detectarse porque el scope funcionaba y el cableado no; un solo nivel no lo
| encuentra.
|
| Al final hay tres casos que no son de filtros sino invariantes del modelo —el
| borrado, el estado y el precio de la línea—. Viven acá por el mismo criterio con
| el que OrdenCompraFiltrosTest termina probando el UNIQUE de sus líneas: son
| afirmaciones sobre el modelo que no tienen pantalla desde donde probarlas, y
| abrirles un archivo propio rompería la convención de dos archivos por módulo.
|
*/

test('buscar encuentra la venta por su numero escrito de varias formas', function () {
    $venta = Venta::factory()->create();
    Venta::factory()->count(2)->create();

    // El número se dicta por teléfono, se copia de la pantalla o se lee de un
    // papel: las tres formas tienen que encontrar la misma venta.
    expect(Venta::buscar($venta->numeroFormateado())->count())->toBe(1)
        ->and(Venta::buscar('V-'.$venta->id)->count())->toBe(1)
        ->and(Venta::buscar((string) $venta->id)->count())->toBe(1);
});

test('buscar encuentra las ventas por la razon social del cliente', function () {
    $tilos = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);
    $otro  = Cliente::factory()->create(['razon_social' => 'Ferretería del Puerto']);

    Venta::factory()->count(2)->create(['cliente_id' => $tilos->id]);
    Venta::factory()->create(['cliente_id' => $otro->id]);

    expect(Venta::buscar('tilos')->count())->toBe(2);
});

test('buscar por nombre no trae las ventas a consumidor final', function () {
    $tilos = Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    Venta::factory()->create(['cliente_id' => $tilos->id]);
    Venta::factory()->count(3)->create();   // cliente_id null

    // Es la regresión del agrupamiento de adentro del whereHas. Sin el closure, la
    // subconsulta sería `clientes.id = ventas.cliente_id AND razon_social LIKE …
    // OR razon_social LIKE …`: devolvería filas por cualquier cliente que coincida,
    // el `exists` daría verdadero y las tres ventas sin cliente también entrarían.
    expect(Venta::buscar('tilos')->count())->toBe(1);
});

test('buscar sin texto no filtra nada', function () {
    Venta::factory()->count(3)->create();

    expect(Venta::buscar(null)->count())->toBe(3)
        ->and(Venta::buscar('')->count())->toBe(3)
        ->and(Venta::buscar('   ')->count())->toBe(3);
});

test('conEstado filtra por estado', function () {
    Venta::factory()->count(2)->create();              // presupuesto
    Venta::factory()->pagada()->create();
    Venta::factory()->entregada()->create();
    Venta::factory()->cancelada()->create();

    expect(Venta::conEstado('presupuesto')->count())->toBe(2)
        ->and(Venta::conEstado('pagada')->count())->toBe(1)
        ->and(Venta::conEstado('entregada')->count())->toBe(1)
        ->and(Venta::conEstado('cancelada')->count())->toBe(1);
});

test('conEstado sin valor o con un estado inexistente no filtra', function () {
    Venta::factory()->count(3)->create();

    // «cobrada» era el nombre del estado en el sistema original y no existe en el
    // ENUM nuevo: tiene que no filtrar, no devolver vacío.
    expect(Venta::conEstado(null)->count())->toBe(3)
        ->and(Venta::conEstado('')->count())->toBe(3)
        ->and(Venta::conEstado('cobrada')->count())->toBe(3);
});

test('conEstado acepta entregada_parcial aunque la etapa 1 no lo alcance', function () {
    Venta::factory()->count(2)->create();

    // El valor está declarado en el ENUM y tiene su texto en ESTADOS, así que el
    // scope lo acepta como filtro válido y devuelve cero. Si lo rechazara, el día
    // que la venta con faltante exista habría que acordarse de tocar el scope.
    expect(Venta::conEstado('entregada_parcial')->count())->toBe(0);
});

test('deCliente filtra por cliente', function () {
    $uno  = Cliente::factory()->create();
    $otro = Cliente::factory()->create();

    Venta::factory()->count(2)->create(['cliente_id' => $uno->id]);
    Venta::factory()->create(['cliente_id' => $otro->id]);
    Venta::factory()->create();   // consumidor final

    expect(Venta::deCliente($uno->id)->count())->toBe(2)
        ->and(Venta::deCliente(null)->count())->toBe(4)
        ->and(Venta::deCliente('abc')->count())->toBe(4);
});

test('deVendedor filtra por quien vendio', function () {
    $sofia  = User::factory()->create();
    $camila = User::factory()->create();

    Venta::factory()->count(2)->create(['usuario_id' => $sofia->id]);
    Venta::factory()->create(['usuario_id' => $camila->id]);

    expect(Venta::deVendedor($sofia->id)->count())->toBe(2)
        ->and(Venta::deVendedor(null)->count())->toBe(3)
        ->and(Venta::deVendedor('abc')->count())->toBe(3);
});

test('desde y hasta recortan por fecha e incluyen el dia completo', function () {
    $this->travelTo(Carbon::parse('2026-03-15 23:40'));
    Venta::factory()->create();

    $this->travelTo(Carbon::parse('2026-04-02 08:00'));
    Venta::factory()->create();

    $this->travelBack();

    expect(Venta::desde('2026-03-01')->hasta('2026-03-31')->count())->toBe(1)
        // Comparar created_at <= la fecha dejaría afuera la de las 23:40.
        ->and(Venta::hasta('2026-03-15')->count())->toBe(1)
        ->and(Venta::desde('2026-04-01')->count())->toBe(1);
});

test('el OR de buscar no se lleva puesto el filtro de estado', function () {
    // La razón social lleva un número a propósito: así el texto buscado activa las
    // dos ramas del OR y el agrupamiento de afuera importa de verdad.
    $cliente = Cliente::factory()->create(['razon_social' => 'Kiosco 24 Horas']);

    Venta::factory()->create(['cliente_id' => $cliente->id]);              // presupuesto
    Venta::factory()->cancelada()->create(['cliente_id' => $cliente->id]);

    // Sin el closure quedaría `cliente LIKE … OR (id = 24 AND estado = …)`, y la
    // rama del nombre traería la cancelada sin pasar por el filtro de estado.
    expect(Venta::buscar('Kiosco 24')->conEstado('presupuesto')->count())->toBe(1);
});

test('una venta no se puede borrar', function () {
    $venta = Venta::factory()->conLinea(Producto::factory()->create(), 2)->create();

    // El núcleo de M-16: SaleDao::delete() era un DELETE plano y detalle_ventas
    // tenía ON DELETE CASCADE, así que borrar una venta borraba su historial.
    expect(fn () => $venta->delete())->toThrow(LogicException::class);

    expect(Venta::count())->toBe(1)
        ->and($venta->lineas()->count())->toBe(1);
});

test('el estado y los importes de la venta no entran por asignacion masiva', function () {
    $venta = Venta::factory()->create();

    $venta->update(['estado' => 'pagada', 'total' => 999999, 'descuento' => 999999]);

    // Se descartan en silencio, que es exactamente lo que queremos: el estado lo
    // mueve la máquina de estados y los importes se derivan de las líneas. Una
    // petición con esos campos no es algo que el servicio tenga que acordarse de
    // ignorar.
    expect($venta->fresh()->estado)->toBe('presupuesto')
        ->and((float) $venta->fresh()->total)->toBe(0.0)
        ->and((float) $venta->fresh()->descuento)->toBe(0.0);
});

test('la linea de venta no acepta el precio por asignacion masiva', function () {
    $venta    = Venta::factory()->create();
    $producto = Producto::factory()->create();

    $linea = $venta->lineas()->make([
        'producto_id'     => $producto->id,
        'cantidad'        => 2,
        'precio_unitario' => 1,       // el intento de manipulación de precios
        'total'           => 1,
    ]);

    // La nota positiva de M-14 que hay que conservar es releer el precio del
    // servidor y nunca confiar en el que manda el cliente. Dejar los congelados
    // fuera de $fillable la convierte en una barrera del modelo y no en una regla
    // que el servicio tiene que recordar.
    expect($linea->cantidad)->toBe(2)
        ->and($linea->precio_unitario)->toBeNull()
        ->and($linea->total)->toBeNull();
});

test('lo pendiente de devolver sale de la cantidad menos lo ya devuelto', function () {
    $producto = Producto::factory()->create();
    $venta    = Venta::factory()->conLinea($producto, 5)->create();
    $linea    = $venta->lineas()->firstOrFail();

    expect($linea->cantidadPendienteDeDevolver())->toBe(5)
        ->and($linea->estaDevueltaPorCompleto())->toBeFalse()
        ->and($venta->load('lineas')->tienePendientesDeDevolver())->toBeTrue();

    // Fuera de $fillable: la mueve sólo la devolución, por asignación directa.
    $linea->cantidad_devuelta = 2;
    $linea->save();

    expect($linea->fresh()->cantidadPendienteDeDevolver())->toBe(3)
        ->and($venta->load('lineas')->tienePendientesDeDevolver())->toBeTrue();

    $linea->cantidad_devuelta = 5;
    $linea->save();

    // Acá es donde el estado de la venta pasa a `devuelta` en lugar de
    // `devuelta_parcial`, y de este predicado sale.
    expect($linea->fresh()->cantidadPendienteDeDevolver())->toBe(0)
        ->and($linea->fresh()->estaDevueltaPorCompleto())->toBeTrue()
        ->and($venta->load('lineas')->tienePendientesDeDevolver())->toBeFalse();
});

test('lo devuelto no entra por asignacion masiva en la linea', function () {
    $producto = Producto::factory()->create();
    $venta    = Venta::factory()->conLinea($producto, 5)->create();
    $linea    = $venta->lineas()->firstOrFail();

    $linea->update(['cantidad_devuelta' => 5]);

    // Se descarta en silencio, que es lo que queremos: `cantidad_devuelta` es el
    // contador que impide devolver más de lo comprado, y si el formulario pudiera
    // escribirlo alguien devolvería dos unidades tres veces y se llevaría seis. El
    // `prohibited` que además lo informa ya está en `VentaRequest`.
    expect($linea->fresh()->cantidad_devuelta)->toBe(0);
});