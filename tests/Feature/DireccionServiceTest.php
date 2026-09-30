<?php

use App\Models\Cliente;
use App\Models\Direccion;
use App\Services\DireccionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DireccionService::class);
    $this->cliente = Cliente::factory()->create();
});

function datosDeDireccion(array $sobreescribir = []): array
{
    return array_merge([
        'calle'             => 'Av. Eva Perón',
        'numero'            => '1450',
        'piso_depto'        => null,
        'codigo_postal'     => '9011',
        'localidad'         => 'Caleta Olivia',
        'provincia'         => 'Santa Cruz',
        'es_predeterminada' => false,
    ], $sobreescribir);
}

/** Cuántas direcciones del cliente están marcadas como predeterminadas. */
function predeterminadasDe(Cliente $cliente): int
{
    return $cliente->direcciones()->where('es_predeterminada', true)->count();
}

/*
|--------------------------------------------------------------------------
| La invariante: si hay direcciones, exactamente una es la predeterminada
|--------------------------------------------------------------------------
|
| El esquema no puede expresarla: es_predeterminada es un boolean con default
| false, así que la base acepta tres marcadas o ninguna. Los dos estados dejan
| al armado de envíos de la Etapa 2 sin saber qué usar.
|
*/

test('la primera direccion queda predeterminada aunque no se pida', function () {
    // Un cliente con una sola dirección y ninguna elegida no tiene sentido, y
    // obligar a marcar el checkbox en el primer alta es pedirle al usuario que
    // resuelva un detalle del modelo.
    $direccion = $this->service->crear($this->cliente, datosDeDireccion());

    expect($direccion->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('la segunda direccion no es predeterminada si no se pide', function () {
    $this->service->crear($this->cliente, datosDeDireccion());
    $segunda = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'San Martín']));

    expect($segunda->es_predeterminada)->toBeFalse()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('dar de alta una predeterminada desmarca la anterior', function () {
    $primera = $this->service->crear($this->cliente, datosDeDireccion());

    $segunda = $this->service->crear($this->cliente, datosDeDireccion([
        'calle'             => 'San Martín',
        'es_predeterminada' => true,
    ]));

    expect($primera->fresh()->es_predeterminada)->toBeFalse()
        ->and($segunda->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('marcar una en la edicion desmarca la anterior', function () {
    $primera = $this->service->crear($this->cliente, datosDeDireccion());
    $segunda = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'San Martín']));

    $this->service->actualizar($segunda, datosDeDireccion([
        'calle'             => 'San Martín',
        'es_predeterminada' => true,
    ]));

    expect($primera->fresh()->es_predeterminada)->toBeFalse()
        ->and($segunda->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('desmarcar la predeterminada no la desmarca', function () {
    // Dejaría al cliente con direcciones y ninguna elegida, y el sistema no
    // tiene con qué decidir cuál pasa a serlo. Se conserva y el mensaje lo
    // explica, en vez de elegir una por su cuenta o perder la edición.
    $primera = $this->service->crear($this->cliente, datosDeDireccion());
    $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'San Martín']));

    $this->service->actualizar($primera, datosDeDireccion([
        'calle'             => 'Av. Eva Perón',
        'numero'            => '1460',
        'es_predeterminada' => false,
    ]));

    expect($primera->fresh()->es_predeterminada)->toBeTrue()
        // Y la corrección del número sí se guardó: el usuario no pierde lo que
        // vino a hacer.
        ->and($primera->fresh()->numero)->toBe('1460')
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('borrar la predeterminada asciende a la mas antigua', function () {
    $primera = $this->service->crear($this->cliente, datosDeDireccion());
    $segunda = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'San Martín']));
    $tercera = $this->service->crear($this->cliente, datosDeDireccion([
        'calle'             => 'Belgrano',
        'es_predeterminada' => true,
    ]));

    $this->service->eliminar($tercera);

    expect($primera->fresh()->es_predeterminada)->toBeTrue()
        ->and($segunda->fresh()->es_predeterminada)->toBeFalse()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('borrar una que no era predeterminada no toca la predeterminada', function () {
    $primera = $this->service->crear($this->cliente, datosDeDireccion());
    $segunda = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'San Martín']));

    $this->service->eliminar($segunda);

    expect($primera->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($this->cliente))->toBe(1);
});

test('borrar la unica direccion deja al cliente sin ninguna', function () {
    // Cero direcciones es un estado válido: el cliente de mostrador nunca
    // necesitó una. La invariante habla de "si tiene al menos una".
    $unica = $this->service->crear($this->cliente, datosDeDireccion());

    $this->service->eliminar($unica);

    expect($this->cliente->direcciones()->count())->toBe(0);
});

test('la invariante se sostiene a lo largo de una secuencia de operaciones', function () {
    $a = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'A']));
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $b = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'B', 'es_predeterminada' => true]));
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $c = $this->service->crear($this->cliente, datosDeDireccion(['calle' => 'C']));
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $this->service->actualizar($c, datosDeDireccion(['calle' => 'C', 'es_predeterminada' => true]));
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $this->service->eliminar($c);
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $this->service->eliminar($a);
    expect(predeterminadasDe($this->cliente))->toBe(1);

    $this->service->eliminar($b);
    expect($this->cliente->direcciones()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Una dirección no se muda a otro cliente
|--------------------------------------------------------------------------
*/

test('el servicio nunca escribe cliente_id', function () {
    // El cliente lo determina la ruta. El Form Request ya rechaza el campo;
    // esto prueba la segunda barrera, que es la que va a proteger a la API de
    // la Etapa 3.
    $ajeno     = Cliente::factory()->create();
    $direccion = $this->service->crear($this->cliente, datosDeDireccion());

    $this->service->actualizar($direccion, datosDeDireccion([
        'cliente_id' => $ajeno->id,
        'calle'      => 'Av. Eva Perón',
    ]));

    expect($direccion->fresh()->cliente_id)->toBe($this->cliente->id)
        ->and($ajeno->direcciones()->count())->toBe(0);
});

test('el alta no toma el cliente del cuerpo de la peticion', function () {
    $ajeno = Cliente::factory()->create();

    $direccion = $this->service->crear($this->cliente, datosDeDireccion(['cliente_id' => $ajeno->id]));

    expect($direccion->cliente_id)->toBe($this->cliente->id);
});

/*
|--------------------------------------------------------------------------
| Datos
|--------------------------------------------------------------------------
*/

test('el piso y departamento es opcional', function () {
    $sin  = $this->service->crear($this->cliente, datosDeDireccion());
    $con  = $this->service->crear($this->cliente, datosDeDireccion(['piso_depto' => '2 B']));

    expect($sin->piso_depto)->toBeNull()
        ->and($con->piso_depto)->toBe('2 B');
});

test('el resumen arma la direccion en una linea', function () {
    // Vive en el modelo para que el listado, el formulario y el diálogo de
    // confirmación digan exactamente lo mismo.
    $direccion = Direccion::factory()->create([
        'cliente_id'    => $this->cliente->id,
        'calle'         => 'Av. Eva Perón',
        'numero'        => '1450',
        'piso_depto'    => '2 B',
        'codigo_postal' => '9011',
        'localidad'     => 'Caleta Olivia',
        'provincia'     => 'Santa Cruz',
    ]);

    expect($direccion->resumen())
        ->toBe('Av. Eva Perón 1450, 2 B — Caleta Olivia, Santa Cruz (9011)');

    $direccion->update(['piso_depto' => null]);

    expect($direccion->fresh()->resumen())
        ->toBe('Av. Eva Perón 1450 — Caleta Olivia, Santa Cruz (9011)');
});