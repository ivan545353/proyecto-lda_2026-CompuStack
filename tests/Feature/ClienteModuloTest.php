<?php

use App\Models\Cliente;
use App\Models\Direccion;
use App\Models\User;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Permisos, ruta por ruta
|--------------------------------------------------------------------------
|
| Cierra C-2 sobre este módulo. El AuthorizationHandlerMiddleware original
| traducía cada acción a una de cuatro banderas CRUD con `?? "can_update"`, así
| que una acción podía quedar habilitada por un permiso que no era el suyo.
|
| Las cinco rutas de direcciones exigen `cliente.editar` a propósito: una
| dirección no tiene existencia autónoma —el esquema la borra en cascada con su
| cliente— y su baja no va con `cliente.eliminar`, que significa dar de baja al
| cliente. Este dataset es lo que fija esa decisión.
|
*/

dataset('rutas de clientes', [
    'listado'            => ['get',    'clientes.index',       'cliente.ver',      'ninguno'],
    'formulario alta'    => ['get',    'clientes.create',      'cliente.crear',    'ninguno'],
    'alta'               => ['post',   'clientes.store',       'cliente.crear',    'ninguno'],
    'formulario edicion' => ['get',    'clientes.edit',        'cliente.editar',   'cliente'],
    'edicion'            => ['put',    'clientes.update',      'cliente.editar',   'cliente'],
    'baja'               => ['delete', 'clientes.destroy',     'cliente.eliminar', 'cliente'],
    'alta de direccion'  => ['get',    'direcciones.create',   'cliente.editar',   'cliente'],
    'guardar direccion'  => ['post',   'direcciones.store',    'cliente.editar',   'cliente'],
    'editar direccion'   => ['get',    'direcciones.edit',     'cliente.editar',   'direccion'],
    'guardar edicion'    => ['put',    'direcciones.update',   'cliente.editar',   'direccion'],
    'baja de direccion'  => ['delete', 'direcciones.destroy',  'cliente.editar',   'direccion'],
]);

const PERMISOS_CLIENTE = ['cliente.ver', 'cliente.crear', 'cliente.editar', 'cliente.eliminar'];

/** Arma la URL de la ruta del dataset según qué parámetros lleve. */
function urlDeCliente(string $ruta, string $tipo, Cliente $cliente, Direccion $direccion): string
{
    return match ($tipo) {
        'ninguno'   => route($ruta),
        'cliente'   => route($ruta, $cliente),
        'direccion' => route($ruta, [$cliente, $direccion]),
    };
}

test('ningun otro permiso del modulo habilita la ruta', function (string $metodo, string $ruta, string $permiso, string $tipo) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $otros = array_values(array_diff(PERMISOS_CLIENTE, [$permiso]));

    pedirRuta(
        $this->actingAs(usuarioCon(...$otros)),
        $metodo,
        urlDeCliente($ruta, $tipo, $cliente, $direccion),
        array_merge(datosDeCliente(), datosDeDireccion()),
    )->assertForbidden();
})->with('rutas de clientes');

test('el permiso de la ruta alcanza para pasar', function (string $metodo, string $ruta, string $permiso, string $tipo) {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $respuesta = pedirRuta(
        $this->actingAs(usuarioCon($permiso)),
        $metodo,
        urlDeCliente($ruta, $tipo, $cliente, $direccion),
        array_merge(datosDeCliente(), datosDeDireccion()),
    );

    expect($respuesta->status())->not->toBe(403);
})->with('rutas de clientes');

test('quien solo puede ver no recibe los botones de alta, edicion ni baja', function () {
    $cliente = Cliente::factory()->create();

    // Ocultar el botón es comodidad; el control real es la ruta, que ya se probó
    // arriba. Esto verifica que la interfaz no ofrezca lo que va a rechazar
    // (M-33: el frontend original mostraba pantallas que el backend rechazaba).
    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index'))
        ->assertOk()
        ->assertDontSee(route('clientes.create'))
        ->assertDontSee(route('clientes.edit', $cliente));
});

/*
|--------------------------------------------------------------------------
| El anidamiento: una dirección no se abre desde la ficha equivocada
|--------------------------------------------------------------------------
|
| Laravel resuelve cada parámetro de la ruta por separado y no verifica que
| estén relacionados. El controlador busca la dirección DENTRO de las del
| cliente; sin eso, /clientes/1/direcciones/99 editaría una dirección del
| cliente 2 desde la ficha del 1.
|
| No se usa scopeBindings(), que sería la forma automática, porque deduce el
| nombre de la relación pluralizando en inglés y Str::plural('direccion') no
| devuelve 'direcciones'.
|
*/

test('la direccion de otro cliente da 404', function () {
    $propietario = Cliente::factory()->create();
    $ajeno       = Cliente::factory()->create();

    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $propietario->id]);

    $usuario = usuarioCon('cliente.editar');

    $this->actingAs($usuario)
        ->get(route('direcciones.edit', [$ajeno, $direccion]))
        ->assertNotFound();

    $this->actingAs($usuario)
        ->put(route('direcciones.update', [$ajeno, $direccion]), datosDeDireccion())
        ->assertNotFound();

    $this->actingAs($usuario)
        ->delete(route('direcciones.destroy', [$ajeno, $direccion]))
        ->assertNotFound();

    // Y no se tocó nada de la dirección real.
    $this->assertModelExists($direccion);
    expect($direccion->fresh()->cliente_id)->toBe($propietario->id);
});

test('no se puede mudar una direccion a otro cliente enviando su id', function () {
    $cliente   = Cliente::factory()->create();
    $ajeno     = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    // El cliente lo determina la ruta, no el cuerpo de la petición. Está
    // declarado prohibido para que el intento se vea en vez de ignorarse:
    // mismo criterio con el que se cerró C-3.
    $this->actingAs(usuarioCon('cliente.editar'))
        ->put(route('direcciones.update', [$cliente, $direccion]), datosDeDireccion([
            'cliente_id' => $ajeno->id,
        ]))
        ->assertSessionHasErrors('cliente_id');

    expect($direccion->fresh()->cliente_id)->toBe($cliente->id);
});

/*
|--------------------------------------------------------------------------
| Filtros y paginación sobre HTTP
|--------------------------------------------------------------------------
|
| A-24 y A-25. Los scopes ya tienen su test en ClienteFiltrosTest; estos prueban
| el cableado entre la URL y el scope, que es lo que estaba roto en el original.
|
*/

test('el parametro q llega al listado y filtra', function () {
    Cliente::factory()->create(['razon_social' => 'Estudio Austral S.R.L.']);
    Cliente::factory()->create(['razon_social' => 'Panadería Los Tilos']);

    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['q' => 'austral']))
        ->assertOk()
        ->assertViewHas('clientes', fn ($clientes) => $clientes->total() === 1
            && $clientes->first()->razon_social === 'Estudio Austral S.R.L.');
});

test('se puede buscar un cuit pegado con guiones', function () {
    // Se lee de una factura como 30-71555888-1 y se guarda sin separadores.
    Cliente::factory()->responsableInscripto()->create(['nro_doc' => '30715558881']);
    Cliente::factory()->create(['nro_doc' => '41556778']);

    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['q' => '30-71555888-1']))
        ->assertViewHas('clientes', fn ($clientes) => $clientes->total() === 1);
});

test('el parametro condicion_iva llega al listado y filtra', function () {
    Cliente::factory()->count(2)->create(['condicion_iva' => 'consumidor_final']);
    Cliente::factory()->responsableInscripto()->create();

    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['condicion_iva' => 'responsable_inscripto']))
        ->assertViewHas('clientes', fn ($clientes) => $clientes->total() === 1);
});

test('el parametro cuenta llega al listado y filtra', function () {
    Cliente::factory()->count(2)->create();
    Cliente::factory()->conCuenta()->create();

    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['cuenta' => 'con_cuenta']))
        ->assertViewHas('clientes', fn ($clientes) => $clientes->total() === 1);
});

test('un filtro inexistente vuelve al listado limpio con aviso', function () {
    $this->actingAs(usuarioCon('cliente.ver'))
        ->get(route('clientes.index', ['condicion_iva' => 'cualquiera']))
        ->assertRedirect(route('clientes.index'))
        ->assertSessionHas('error');
});

test('el listado pagina de a 15 y los enlaces conservan el filtro', function () {
    Cliente::factory()->count(20)
        ->sequence(fn ($s) => ['razon_social' => "Comercio Austral {$s->index}"])
        ->create();

    $usuario = usuarioCon('cliente.ver');

    $this->actingAs($usuario)
        ->get(route('clientes.index', ['q' => 'austral']))
        ->assertViewHas('clientes', fn ($clientes) => $clientes->count() === 15
            && $clientes->total() === 20
            // Sin withQueryString(), la página 2 perdería el filtro.
            && str_contains($clientes->nextPageUrl(), 'q=austral'));

    $this->actingAs($usuario)
        ->get(route('clientes.index', ['q' => 'austral', 'page' => 2]))
        ->assertViewHas('clientes', fn ($clientes) => $clientes->count() === 5);
});

/*
|--------------------------------------------------------------------------
| Validación fiscal: rechaza, no vacía
|--------------------------------------------------------------------------
|
| M-31. Los setters originales convertían en cadena vacía lo que no validaba y
| el dato se guardaba igual; el mensaje resultante decía "es obligatorio", que
| manda al usuario a completar un campo que sí completó.
|
| Estas tres pruebas venían del módulo de personal, donde vivían las reglas
| fiscales antes de extraerse a App\Support\ReglasFiscales. Ahora la clase la
| usa ClienteRequest y es acá donde corresponde probarla sobre HTTP.
|
*/

test('quien factura A necesita numero de documento', function () {
    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente([
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '',
            'condicion_iva' => 'responsable_inscripto',
        ]))
        ->assertSessionHasErrors([
            'nro_doc' => 'Para esa condición frente al IVA hace falta el número de documento.',
        ]);

    expect(Cliente::count())->toBe(0);
});

test('el consumidor final puede no estar identificado', function () {
    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente(['nro_doc' => '']))
        ->assertSessionHasNoErrors();

    // Nulo, no cadena vacía: sólo NULL no colisiona consigo mismo en el
    // UNIQUE(tipo_doc, nro_doc), que es lo que permite dos de mostrador sin
    // identificar.
    expect(Cliente::first()->nro_doc)->toBeNull();
});

test('un documento mal escrito se rechaza por lo que es, no por estar vacio', function () {
    $this->actingAs(usuarioCon('cliente.crear'))
        ->from(route('clientes.create'))
        ->post(route('clientes.store'), datosDeCliente([
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '30-ABC-1',
            'condicion_iva' => 'responsable_inscripto',
        ]))
        ->assertRedirect(route('clientes.create'))
        ->assertSessionHasErrors(['nro_doc' => 'El CUIT/CUIL tiene 11 dígitos.'])
        // Y conserva lo que el usuario escribió, sin vaciarlo.
        ->assertSessionHasInput('nro_doc', '30-ABC-1');

    expect(Cliente::count())->toBe(0);
});

test('el largo del documento depende del tipo', function () {
    $usuario = usuarioCon('cliente.crear');

    // Un DNI de 11 dígitos no es un DNI.
    $this->actingAs($usuario)
        ->post(route('clientes.store'), datosDeCliente(['tipo_doc' => 'dni', 'nro_doc' => '30715558881']))
        ->assertSessionHasErrors(['nro_doc' => 'El DNI se escribe sin puntos, entre 6 y 9 dígitos.']);

    // Y un CUIT de 8 tampoco es un CUIT.
    $this->actingAs($usuario)
        ->post(route('clientes.store'), datosDeCliente(['tipo_doc' => 'cuit', 'nro_doc' => '41556778']))
        ->assertSessionHasErrors('nro_doc');
});

test('el cuit se guarda normalizado aunque se escriba con guiones', function () {
    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente([
            'razon_social'  => 'Estudio Austral S.R.L.',
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '30-71555888-1',
            'condicion_iva' => 'responsable_inscripto',
        ]))
        ->assertSessionHas('exito');

    expect(Cliente::first()->nro_doc)->toBe('30715558881');
});

test('un documento repetido del mismo tipo se rechaza con un mensaje que lo explica', function () {
    Cliente::factory()->responsableInscripto()->create(['tipo_doc' => 'cuit', 'nro_doc' => '30715558881']);

    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente([
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '30715558881',
            'condicion_iva' => 'responsable_inscripto',
        ]))
        ->assertSessionHasErrors(['nro_doc' => 'Ya hay un cliente registrado con ese documento.']);
});

test('el mismo numero con otro tipo de documento si se acepta', function () {
    // La base tiene UNIQUE(tipo_doc, nro_doc), no UNIQUE(nro_doc): la regla dice
    // lo mismo antes, para que el usuario reciba un mensaje y no un error de
    // integridad.
    Cliente::factory()->responsableInscripto()->create(['tipo_doc' => 'cuit', 'nro_doc' => '30715558881']);

    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente([
            'razon_social'  => 'Otra empresa',
            'tipo_doc'      => 'cuil',
            'nro_doc'       => '30715558881',
            'condicion_iva' => 'monotributo',
        ]))
        ->assertSessionHasNoErrors();

    expect(Cliente::count())->toBe(2);
});

test('la cuenta de acceso no se puede asignar desde este modulo', function () {
    // La invariante rol↔satélite sólo la sabe mantener el módulo de personal, y
    // en la Etapa 1 ninguna pantalla crea cuentas de tienda.
    $ajeno = User::factory()->conRol('Cliente')->create();

    $this->actingAs(usuarioCon('cliente.crear'))
        ->post(route('clientes.store'), datosDeCliente(['user_id' => $ajeno->id]))
        ->assertSessionHasErrors('user_id');

    expect(Cliente::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Alta y edición
|--------------------------------------------------------------------------
*/

test('el alta lleva a la ficha del cliente para cargarle direcciones', function () {
    $this->actingAs(usuarioCon('cliente.crear', 'cliente.editar'))
        ->post(route('clientes.store'), datosDeCliente())
        ->assertRedirect(route('clientes.edit', Cliente::first()))
        ->assertSessionHas('exito');
});

test('la edicion guarda los datos fiscales', function () {
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon('cliente.editar'))
        ->put(route('clientes.update', $cliente), datosDeCliente([
            'razon_social'  => 'Estudio Austral S.R.L.',
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '30715558881',
            'condicion_iva' => 'responsable_inscripto',
        ]))
        ->assertRedirect(route('clientes.index'))
        ->assertSessionHas('exito');

    $cliente = $cliente->fresh();

    expect($cliente->razon_social)->toBe('Estudio Austral S.R.L.')
        ->and($cliente->tipoComprobante())->toBe('factura_a');
});

/*
|--------------------------------------------------------------------------
| Direcciones sobre HTTP
|--------------------------------------------------------------------------
|
| La invariante ya tiene su test en DireccionServiceTest; estos prueban que la
| pantalla no diga una cosa y el sistema haga otra.
|
*/

test('la primera direccion queda predeterminada y el mensaje lo dice', function () {
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon('cliente.editar'))
        ->post(route('direcciones.store', $cliente), datosDeDireccion())
        ->assertRedirect(route('clientes.edit', $cliente))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'predeterminada'));

    expect(predeterminadasDe($cliente))->toBe(1);
});

test('el formulario de la primera direccion llega con el checkbox marcado', function () {
    // Si llegara destildado, el sistema la marcaría igual y la pantalla habría
    // dicho una cosa distinta del resultado.
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon('cliente.editar'))
        ->get(route('direcciones.create', $cliente))
        ->assertOk()
        ->assertViewHas('direccion', fn ($direccion) => $direccion->es_predeterminada === true);
});

test('marcar una direccion desmarca la anterior', function () {
    $cliente = Cliente::factory()->create();
    $primera = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    $segunda = Direccion::factory()->create(['cliente_id' => $cliente->id]);

    $this->actingAs(usuarioCon('cliente.editar'))
        ->put(route('direcciones.update', [$cliente, $segunda]), datosDeDireccion([
            'es_predeterminada' => 1,
        ]))
        ->assertSessionHas('exito');

    expect($primera->fresh()->es_predeterminada)->toBeFalse()
        ->and($segunda->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($cliente))->toBe(1);
});

test('destildar la predeterminada guarda el resto y lo avisa', function () {
    $cliente = Cliente::factory()->create();
    $primera = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);
    Direccion::factory()->create(['cliente_id' => $cliente->id]);

    $this->actingAs(usuarioCon('cliente.editar'))
        ->put(route('direcciones.update', [$cliente, $primera]), datosDeDireccion([
            'numero'            => '1460',
            'es_predeterminada' => 0,
        ]))
        // El mensaje dice lo que pasó de verdad: guardar algo distinto de lo que
        // el usuario marcó sin avisarle es mentirle sobre el estado del sistema.
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'marcá otra'));

    expect($primera->fresh()->es_predeterminada)->toBeTrue()
        // Y la corrección sí se guardó: no pierde lo que vino a hacer.
        ->and($primera->fresh()->numero)->toBe('1460');
});

test('borrar la predeterminada asciende otra y el mensaje lo anuncia', function () {
    $cliente = Cliente::factory()->create();
    $vieja   = Direccion::factory()->create(['cliente_id' => $cliente->id]);
    $actual  = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->actingAs(usuarioCon('cliente.editar'))
        ->delete(route('direcciones.destroy', [$cliente, $actual]))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'más antigua'));

    expect($vieja->fresh()->es_predeterminada)->toBeTrue()
        ->and(predeterminadasDe($cliente))->toBe(1);
});

test('una provincia fuera de la lista se rechaza', function () {
    // Lista cerrada: con texto libre "Santa Cruz", "Sta Cruz" y "SANTA CRUZ"
    // serían tres provincias, y la cotización de envío de la Etapa 2 necesita
    // una sola.
    $cliente = Cliente::factory()->create();

    $this->actingAs(usuarioCon('cliente.editar'))
        ->post(route('direcciones.store', $cliente), datosDeDireccion(['provincia' => 'Sta Cruz']))
        ->assertSessionHasErrors(['provincia' => 'Elegí una provincia de la lista.']);

    expect($cliente->direcciones()->count())->toBe(0);
});

test('el codigo postal acepta los dos formatos del pais y rechaza cualquier otro', function () {
    $cliente = Cliente::factory()->create();
    $usuario = usuarioCon('cliente.editar');

    // El de cuatro dígitos y el CPA. Rechazar el viejo dejaría afuera a quien
    // copia la dirección de una factura.
    foreach (['9011', 'Z9011XAA'] as $valido) {
        $this->actingAs($usuario)
            ->post(route('direcciones.store', $cliente), datosDeDireccion(['codigo_postal' => $valido]))
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($usuario)
        ->post(route('direcciones.store', $cliente), datosDeDireccion(['codigo_postal' => '90']))
        ->assertSessionHasErrors('codigo_postal');

    expect($cliente->direcciones()->count())->toBe(2);
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

test('un cliente sin ventas ni cuenta se elimina con sus direcciones', function () {
    $cliente   = Cliente::factory()->create();
    $direccion = Direccion::factory()->predeterminada()->create(['cliente_id' => $cliente->id]);

    $this->actingAs(usuarioCon('cliente.eliminar'))
        ->delete(route('clientes.destroy', $cliente))
        ->assertRedirect(route('clientes.index'))
        ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, 'Se eliminó'));

    $this->assertModelMissing($cliente);
    // Cascada del esquema: una dirección sin cliente no significa nada.
    $this->assertModelMissing($direccion);
});

test('un cliente con ventas no se elimina y el mensaje explica por que', function () {
    $cliente = Cliente::factory()->create();

    // La tabla existe desde la Fase 1; el modelo Venta llega en la Fase 6.
    DB::table('ventas')->insert([
        'cliente_id' => $cliente->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs(usuarioCon('cliente.eliminar'))
        ->delete(route('clientes.destroy', $cliente))
        // La ReglaDeNegocioException se traduce a mensaje en bootstrap/app.php,
        // una sola vez para toda la aplicación (M-30, donde toda excepción
        // volvía como un 400 genérico).
        ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'operaciones registradas'));

    $this->assertModelExists($cliente);
});

test('un cliente con cuenta de acceso no se elimina desde este modulo', function () {
    // Borrarlo dejaría un usuario de ámbito tienda sin satélite: rompería la
    // invariante que sostiene toda la fase.
    $usuario = User::factory()->conRol('Cliente')->create();
    $cliente = Cliente::factory()->create(['user_id' => $usuario->id]);

    $this->actingAs(usuarioCon('cliente.eliminar'))
        ->delete(route('clientes.destroy', $cliente))
        ->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'cuenta de acceso'));

    $this->assertModelExists($cliente);
});