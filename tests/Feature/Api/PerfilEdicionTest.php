<?php

use App\Models\Cliente;
use App\Services\UsuarioService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    // Cuenta de tienda con su ficha, como la da de alta el local (con documento).
    $this->tiendaCuenta = fn (array $cliente = []) => app(UsuarioService::class)->crear(datosDeClienteDeTienda([
        'cliente' => array_merge(datosDeClienteDeTienda()['cliente'], $cliente),
    ]));

    $this->tiendaPerfil = fn ($usuario) => $this->actingAs($usuario, 'sanctum')->getJson('/api/v1/perfil');
});

/*
|--------------------------------------------------------------------------
| Lectura: los datos fiscales que viajan
|--------------------------------------------------------------------------
*/

test('el perfil trae el documento y la condición frente al IVA con sus textos', function () {
    $respuesta = ($this->tiendaPerfil)(($this->tiendaCuenta)());

    $respuesta->assertOk()
        ->assertJsonPath('datos.tipo_doc', 'dni')
        ->assertJsonPath('datos.tipo_doc_texto', 'DNI')
        ->assertJsonPath('datos.nro_doc', '41556778')
        ->assertJsonPath('datos.condicion_iva', 'consumidor_final')
        ->assertJsonPath('datos.condicion_iva_texto', 'Consumidor final');
    expect(array_keys($respuesta->json('datos')))->toBe([
        'nombre', 'apellido', 'email', 'telefono', 'razon_social', 'tipo_doc', 'tipo_doc_texto',
        'nro_doc', 'condicion_iva', 'condicion_iva_texto', 'direccion',
    ]);
});

test('el texto sigue al tipo de documento y a la condición que cargó el local', function () {
    $respuesta = ($this->tiendaPerfil)(($this->tiendaCuenta)([
        'tipo_doc' => 'cuit', 'nro_doc' => '30715558881', 'condicion_iva' => 'responsable_inscripto',
    ]));

    $respuesta->assertOk()
        ->assertJsonPath('datos.tipo_doc_texto', 'CUIT')
        ->assertJsonPath('datos.nro_doc', '30715558881')
        ->assertJsonPath('datos.condicion_iva_texto', 'Responsable inscripto');
});

test('sin número de documento, el tipo no viaja', function () {
    ($this->tiendaPerfil)(($this->tiendaCuenta)(['nro_doc' => null]))
        ->assertOk()
        ->assertJsonPath('datos.tipo_doc', null)
        ->assertJsonPath('datos.tipo_doc_texto', null)
        ->assertJsonPath('datos.nro_doc', null);
});

/*
|--------------------------------------------------------------------------
| PATCH /perfil
|--------------------------------------------------------------------------
*/

describe('edición', function () {
    beforeEach(function () {
        $this->tiendaUsuario = ($this->tiendaCuenta)();
        $this->tiendaEditar  = fn (array $cuerpo) => $this->actingAs($this->tiendaUsuario, 'sanctum')
            ->patchJson('/api/v1/perfil', $cuerpo);
        $this->tiendaDireccion = fn (array $cambios = []) => array_merge([
            'calle'         => 'San Martín',
            'numero'        => '450',
            'piso_depto'    => '2 B',
            'codigo_postal' => '9000',
            'localidad'     => 'Comodoro Rivadavia',
            'provincia'     => 'Chubut',
        ], $cambios);
    });

    test('edita nombre, apellido, teléfono, documento y dirección, y el GET devuelve lo nuevo', function () {
        $respuesta = ($this->tiendaEditar)([
            'nombre'    => 'Camila Inés',
            'apellido'  => 'Herrera Paz',
            'telefono'  => '297-6000000',
            'tipo_doc'  => 'cuit',
            'nro_doc'   => '20-12345678-6',
            'direccion' => ($this->tiendaDireccion)(),
        ]);

        $respuesta->assertOk()->assertJsonPath('mensaje', 'Guardamos tus datos.');
        app('auth')->forgetGuards();
        $perfil = ($this->tiendaPerfil)($this->tiendaUsuario->fresh())->assertOk()->json('datos');

        expect($respuesta->json('datos'))->toBe($perfil)
            ->and($perfil)->toMatchArray([
                'nombre'       => 'Camila Inés',
                'apellido'     => 'Herrera Paz',
                'email'        => 'camila@sistema.local',
                'telefono'     => '297-6000000',
                'tipo_doc'     => 'cuit',
                'nro_doc'      => '20123456786',
                'direccion'    => ($this->tiendaDireccion)(),
            ]);
    });

    test('la razón social sigue al nombre y al apellido, también si cambia uno solo', function () {
        ($this->tiendaEditar)(['apellido' => 'Paz'])->assertOk()->assertJsonPath('datos.razon_social', 'Camila Paz');

        expect($this->tiendaUsuario->cliente->fresh()->razon_social)->toBe('Camila Paz');
    });

    test('lo que no vino no se toca: la razón social que ajustó el cajero sigue', function () {
        $this->tiendaUsuario->cliente->update(['razon_social' => 'Camila Herrera (taller)']);

        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)()])->assertOk();

        expect($this->tiendaUsuario->cliente->fresh())
            ->razon_social->toBe('Camila Herrera (taller)')
            ->telefono->toBe('297-5123456')
            ->nro_doc->toBe('41556778');
    });

    test('tipo y número en null quitan el documento; la dirección en null, la dirección', function () {
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)()])->assertOk();

        ($this->tiendaEditar)(['tipo_doc' => null, 'nro_doc' => null, 'direccion' => null])
            ->assertOk()
            ->assertJsonPath('datos.tipo_doc', null)
            ->assertJsonPath('datos.nro_doc', null)
            ->assertJsonPath('datos.direccion', null);

        expect($this->tiendaUsuario->cliente->fresh()->nro_doc)->toBeNull()
            ->and($this->tiendaUsuario->cliente->direcciones()->count())->toBe(0);
    });

    test('cambiar la dirección edita la predeterminada, no suma otra', function () {
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)()])->assertOk();
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)(['calle' => 'Rivadavia'])])
            ->assertOk()
            ->assertJsonPath('datos.direccion.calle', 'Rivadavia');

        expect($this->tiendaUsuario->cliente->direcciones()->count())->toBe(1);
    });

    test('editar la dirección no cambia la de un pedido ya confirmado', function () {
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)()])->assertOk();
        $pedido = \App\Models\Venta::factory()->create(['cliente_id' => $this->tiendaUsuario->cliente->id]);
        $pedido->forceFill(['modo_entrega' => 'envio', 'entrega_calle' => 'San Martín', 'entrega_numero' => '450'])->save();

        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)(['calle' => 'Rivadavia', 'numero' => '12'])])->assertOk();
        ($this->tiendaEditar)(['direccion' => null])->assertOk();

        expect($pedido->fresh())->entrega_calle->toBe('San Martín')->entrega_numero->toBe('450');
    });

    test('lo que se edita en la app es lo que ve el cajero en la ficha', function () {
        ($this->tiendaEditar)(['apellido' => 'Paz', 'tipo_doc' => 'cuit', 'nro_doc' => '20123456786'])->assertOk();
        $cliente = $this->tiendaUsuario->cliente->fresh();

        app('auth')->forgetGuards();
        $this->actingAs(admin())->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('Camila Paz')
            ->assertSee('20123456786');
    });

    test('sin credencial es 401; una cuenta de gestión no llega (D-3)', function () {
        $this->patchJson('/api/v1/perfil', ['nombre' => 'Otro'])
            ->assertStatus(401)
            ->assertJsonPath('codigo', 'sin_sesion');

        $gestion = admin();
        $this->patchJson('/api/v1/perfil', ['nombre' => 'Otro'], [
            'Authorization' => 'Bearer '.app(\App\Services\CredencialService::class)->emitir($gestion)['credencial'],
        ])->assertStatus(401)->assertJsonPath('codigo', 'sesion_invalida');

        expect($gestion->fresh()->nombre)->not->toBe('Otro');
    });

    test('un documento bien escrito pasa, también el que ya tiene la cuenta', function (string $tipo, string $numero) {
        ($this->tiendaEditar)(['tipo_doc' => $tipo, 'nro_doc' => $numero])->assertOk();
    })->with([
        'CUIL válido'     => ['cuil', '27301112225'],
        'DNI de 7'        => ['dni', '9123456'],
        'DNI con puntos'  => ['dni', '41.556.778'],
        'el que ya tiene' => ['dni', '41556778'],
    ]);

    test('un dato inválido es 422 en su campo', function (array $cuerpo, string $campo) {
        ($this->tiendaEditar)($cuerpo)
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'validacion')
            ->assertJsonValidationErrors($campo, 'errores');
    })->with([
        'tipo sin número'             => [['tipo_doc' => 'dni'], 'nro_doc'],
        'tipo con número en null'     => [['tipo_doc' => 'dni', 'nro_doc' => null], 'nro_doc'],
        'número sin tipo'             => [['nro_doc' => '41556778'], 'tipo_doc'],
        'tipo que no existe'          => [['tipo_doc' => 'pasaporte', 'nro_doc' => '41556778'], 'tipo_doc'],
        'DNI de 6'                    => [['tipo_doc' => 'dni', 'nro_doc' => '123456'], 'nro_doc'],
        'DNI de 9'                    => [['tipo_doc' => 'dni', 'nro_doc' => '123456789'], 'nro_doc'],
        'DNI con letras'              => [['tipo_doc' => 'dni', 'nro_doc' => '41A56778'], 'nro_doc'],
        'CUIT con el verificador mal' => [['tipo_doc' => 'cuit', 'nro_doc' => '20123456787'], 'nro_doc'],
        'CUIT de 10'                  => [['tipo_doc' => 'cuit', 'nro_doc' => '2012345678'], 'nro_doc'],
        'CUIL con el verificador mal' => [['tipo_doc' => 'cuil', 'nro_doc' => '27301112224'], 'nro_doc'],
        'nombre vacío'                => [['nombre' => ''], 'nombre'],
        'nombre de 101'               => [['nombre' => str_repeat('a', 101)], 'nombre'],
        'nombre y apellido sobre 150' => [['nombre' => str_repeat('a', 80), 'apellido' => str_repeat('b', 80)], 'apellido'],
        'teléfono de 31'              => [['telefono' => str_repeat('1', 31)], 'telefono'],
        'dirección sin calle'         => [['direccion' => ['numero' => '450']], 'direccion.calle'],
        'calle de 151'                => [['direccion' => ['calle' => str_repeat('a', 151)]], 'direccion.calle'],
        'dirección que no es objeto'  => [['direccion' => 'San Martín 450'], 'direccion'],
    ]);

    test('una provincia fuera de la lista es 422 en su campo y no toca la dirección (HL-69)', function () {
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)(['provincia' => 'Chubutt'])])
            ->assertStatus(422)
            ->assertJsonPath('errores', ['direccion.provincia' => ['Elegí una provincia de la lista.']]);

        expect($this->tiendaUsuario->cliente->direcciones()->count())->toBe(0);
    });

    test('una provincia de la lista se guarda tal cual', function () {
        ($this->tiendaEditar)(['direccion' => ($this->tiendaDireccion)(['provincia' => 'Ciudad Autónoma de Buenos Aires'])])
            ->assertOk()
            ->assertJsonPath('datos.direccion.provincia', 'Ciudad Autónoma de Buenos Aires');

        expect($this->tiendaUsuario->cliente->direcciones()->sole()->provincia)->toBe('Ciudad Autónoma de Buenos Aires');
    });

    test('el nombre solo se mide contra el apellido guardado', function () {
        $this->tiendaUsuario->update(['apellido' => str_repeat('b', 100)]);

        ($this->tiendaEditar)(['nombre' => str_repeat('a', 60)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre', 'errores');
    });

    test('lo fiscal, el correo y los importes no se mandan', function (string $campo, mixed $valor) {
        ($this->tiendaEditar)(['nombre' => 'Camila', $campo => $valor])
            ->assertStatus(422)
            ->assertJsonValidationErrors($campo, 'errores');
    })->with([
        'condición frente al IVA' => ['condicion_iva', 'responsable_inscripto'],
        'correo'                  => ['email', 'otra@correo.local'],
        'razón social'            => ['razon_social', 'Otra S.A.'],
        'un precio'               => ['precio', '100.00'],
        'un total'                => ['total', '100.00'],
    ]);

    test('un documento de otro cliente es 422', function () {
        Cliente::create([
            'razon_social'  => 'Otro cliente',
            'tipo_doc'      => 'cuit',
            'nro_doc'       => '20123456786',
            'condicion_iva' => 'consumidor_final',
        ]);

        ($this->tiendaEditar)(['tipo_doc' => 'cuit', 'nro_doc' => '20123456786'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nro_doc' => 'otra cuenta'], 'errores');
    });

    test('quien factura A o es monotributista no puede quitar el documento', function (string $condicion) {
        $this->tiendaUsuario->cliente->update(['tipo_doc' => 'cuit', 'nro_doc' => '20123456786', 'condicion_iva' => $condicion]);

        ($this->tiendaEditar)(['tipo_doc' => null, 'nro_doc' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nro_doc', 'errores');
    })->with(['responsable_inscripto', 'monotributo']);
});
