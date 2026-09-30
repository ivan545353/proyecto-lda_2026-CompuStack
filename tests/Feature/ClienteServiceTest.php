<?php

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Cliente;
use App\Models\Direccion;
use App\Models\User;
use App\Services\ClienteService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ClienteService::class);
});


/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta crea un cliente de mostrador', function () {
    $cliente = $this->service->crear(datosDeCliente());

    expect($cliente->razon_social)->toBe('Panadería Los Tilos')
        ->and($cliente->esDeMostrador())->toBeTrue()
        ->and($cliente->tipoComprobante())->toBe('factura_b');
});

test('el servicio nunca escribe la cuenta de acceso', function () {
    // La invariante rol↔satélite sólo la sabe mantener el módulo de personal.
    // El Form Request ya lo rechaza; esto prueba la segunda barrera, que es la
    // que va a proteger a la API de la Etapa 3.
    $usuario = User::factory()->create();

    $cliente = $this->service->crear(datosDeCliente(['user_id' => $usuario->id]));

    expect($cliente->user_id)->toBeNull();
});

test('actualizar no cambia la cuenta de acceso', function () {
    $this->seed(RolPermisoSeeder::class);

    $usuario = User::factory()->conRol('Cliente')->create();
    $cliente = Cliente::factory()->create(['user_id' => $usuario->id]);

    $this->service->actualizar($cliente, datosDeCliente([
        'user_id'      => null,
        'razon_social' => 'Camila Herrera',
    ]));

    expect($cliente->fresh()->user_id)->toBe($usuario->id)
        ->and($cliente->fresh()->razon_social)->toBe('Camila Herrera');
});

test('un documento en blanco se guarda como nulo, no como cadena vacia', function () {
    // Sólo NULL no colisiona con NULL en el UNIQUE(tipo_doc, nro_doc). Si se
    // guardara '', el segundo consumidor final sin documento chocaría.
    $primero = $this->service->crear(datosDeCliente(['nro_doc' => '']));
    $segundo = $this->service->crear(datosDeCliente([
        'razon_social' => 'Otro mostrador',
        'nro_doc'      => null,
        'email'        => null,
    ]));

    expect($primero->nro_doc)->toBeNull()
        ->and($segundo->nro_doc)->toBeNull()
        ->and(Cliente::count())->toBe(2);
});

test('el responsable inscripto queda con factura A', function () {
    $cliente = $this->service->crear(datosDeCliente([
        'razon_social'  => 'Estudio Austral S.R.L.',
        'tipo_doc'      => 'cuit',
        'nro_doc'       => '30715558881',
        'condicion_iva' => 'responsable_inscripto',
    ]));

    expect($cliente->tipoComprobante())->toBe('factura_a');
});

/*
|--------------------------------------------------------------------------
| La baja: se rechaza, no se desactiva
|--------------------------------------------------------------------------
|
| Única excepción del proyecto a "lo que está referenciado se desactiva".
| `clientes` no tiene columna `activo` y no hace falta: un cliente no se elige
| de un desplegable, se busca. Rechazar la baja conserva el dato igual y además
| lo explica.
|
*/

test('un cliente sin ventas ni cuenta se elimina, con sus direcciones', function () {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->service->eliminar($cliente);

    $this->assertModelMissing($cliente);
    // Cascada del esquema: una dirección sin cliente no significa nada.
    $this->assertModelMissing($direccion);
});

test('un cliente con ventas no se elimina', function () {
    $cliente = Cliente::factory()->create();

    // La tabla existe desde la Fase 1; el modelo Venta llega en la Fase 6.
    DB::table('ventas')->insert([
        'cliente_id' => $cliente->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $this->service->eliminar($cliente))
        ->toThrow(ReglaDeNegocioException::class);

    $this->assertModelExists($cliente);
});

test('un cliente con cuenta de acceso no se elimina desde este modulo', function () {
    // Borrarlo dejaría un usuario de ámbito tienda sin satélite: rompería la
    // invariante que sostiene toda la fase.
    $this->seed(RolPermisoSeeder::class);

    $usuario = User::factory()->conRol('Cliente')->create();
    $cliente = Cliente::factory()->create(['user_id' => $usuario->id]);

    expect(fn () => $this->service->eliminar($cliente))
        ->toThrow(ReglaDeNegocioException::class);

    $this->assertModelExists($cliente);
});

test('el mensaje de la baja rechazada explica el motivo', function () {
    // M-30: el ExceptionHandler original mapeaba toda excepción a un 400 con un
    // mensaje genérico. Acá el usuario lee qué pasó y qué hacer.
    $cliente = Cliente::factory()->create();

    DB::table('ventas')->insert([
        'cliente_id' => $cliente->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $this->service->eliminar($cliente);
    } catch (ReglaDeNegocioException $e) {
        expect($e->getMessage())->toContain('operaciones registradas')
            ->and($e->getMessage())->toContain($cliente->razon_social);
    }
});