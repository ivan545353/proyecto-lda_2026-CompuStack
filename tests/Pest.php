<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Un usuario de gestión que tiene exactamente el permiso indicado, y nada más.
 *
 * Crea un rol descartable en vez de reutilizar los de sistema: así el test
 * verifica el permiso que nombra y no hereda otros por accidente.
 */
function usuarioCon(string ...$claves): \App\Models\User
{
    $rol = \App\Models\Rol::create([
        'nombre'     => 'Prueba '.\Illuminate\Support\Str::random(8),
        'ambito'     => 'gestion',
        'es_sistema' => false,
    ]);

    $rol->permisos()->sync(
        \App\Models\Permiso::whereIn('clave', $claves)->pluck('id')
    );

    return \App\Models\User::factory()->create(['rol_id' => $rol->id]);
}

/**
 * Un usuario con rol de Administrador para pruebas que requieren privilegios completos.
 */
function admin(): \App\Models\User
{
    return \App\Models\User::factory()->conRol('Administrador')->create();
}

