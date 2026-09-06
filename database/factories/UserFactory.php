<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    protected static ?string $passwordDePrueba = null;

    public function definition(): array
    {
        return [
            'nombre'            => fake('es_AR')->firstName(),
            'apellido'          => fake('es_AR')->lastName(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$passwordDePrueba ??= bcrypt('password'),
            'rol_id'            => Rol::where('nombre', 'Vendedor')->value('id')
                                    ?? Rol::factory(),
            'activo'            => true,
            'remember_token'    => Str::random(10),
        ];
    }

    /** Usuario con un rol concreto: User::factory()->conRol('Cajero')->create() */
    public function conRol(string $nombre): static
    {
        return $this->state(fn () => [
            'rol_id' => Rol::where('nombre', $nombre)->firstOrFail()->id,
        ]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
