<?php

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\IntentoPago;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\User;
use App\Models\Venta;
use App\Services\CredencialService;
use Database\Seeders\RolPermisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Escala de «Mis pedidos»: las consultas no crecen con los pedidos
|--------------------------------------------------------------------------
|
| El renglón lee el estado del pago (el último intento) y las unidades: dos lugares donde
| aparece una consulta por pedido. Se mide por comparación, como `CatalogoEscalaTest`: las
| mismas consultas con 3 pedidos que con 30, en una sola página.
|
*/

beforeEach(function () {
    $this->seed(RolPermisoSeeder::class);

    $this->cuenta = User::factory()->create(['rol_id' => Rol::where('nombre', 'Cliente')->firstOrFail()->id]);
    $this->cliente = Cliente::factory()->create(['user_id' => $this->cuenta->id]);
    $this->bearer = ['Authorization' => 'Bearer '.app(CredencialService::class)->emitir($this->cuenta)['credencial']];
    $this->producto = Producto::factory()->conStockYCosto(500, 30000)->create([
        'categoria_id' => Categoria::factory()->create()->id,
    ]);

    // Pedidos en todas las situaciones del pago: sin intento, con uno o con dos, pagados y de contado.
    $this->pedidos = function (int $cuantos): void {
        foreach (range(1, $cuantos) as $n) {
            $venta = Venta::factory()->conLinea($this->producto, 1 + $n % 3)->create([
                'cliente_id' => $this->cliente->id,
                'usuario_id' => null,
                'canal'      => 'online',
                'estado'     => $n % 5 === 0 ? 'pagada' : 'pendiente_pago',
                'medio_pago' => $n % 4 === 0 ? 'contado' : 'pasarela',
            ]);

            foreach (range(1, $n % 3) as $i) {
                IntentoPago::forceCreate([
                    'venta_id'   => $venta->id,
                    'pasarela'   => 'mercadopago',
                    'estado'     => $i === 1 ? 'rechazado' : 'pendiente',
                    'importe'    => '1000.00',
                    'referencia' => (string) Str::uuid(),
                    'vence_at'   => now()->addMinutes(20),
                ]);
            }
        }
    };

    $this->medir = fn () => contarConsultas(fn () => $this->getJson('/api/v1/pedidos?por_pagina=50', $this->bearer)->assertOk());
});

test('las consultas del listado no crecen con los pedidos', function () {
    ($this->pedidos)(3);
    $this->getJson('/api/v1/pedidos', $this->bearer)->assertOk();   // calienta lo que se lee una vez
    $pocos = ($this->medir)();

    ($this->pedidos)(27);
    $muchos = ($this->medir)();

    $this->getJson('/api/v1/pedidos?por_pagina=50', $this->bearer)->assertJsonCount(30, 'datos');
    expect($muchos)->toBe($pocos);
});
