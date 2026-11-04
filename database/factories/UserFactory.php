<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'rol' => Rol::AuxiliarFarmacia,
            'remember_token' => Str::random(10),
        ];
    }

    public function rol(Rol $rol): static
    {
        return $this->state(fn (array $attributes) => ['rol' => $rol]);
    }

    public function auxiliar(): static
    {
        return $this->rol(Rol::AuxiliarFarmacia);
    }

    public function regente(): static
    {
        return $this->rol(Rol::RegenteFarmacia);
    }

    public function medico(): static
    {
        return $this->rol(Rol::Medico);
    }

    public function auditor(): static
    {
        return $this->rol(Rol::Auditor);
    }

    public function admin(): static
    {
        return $this->rol(Rol::Admin);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
