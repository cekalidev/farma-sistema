<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

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
            'password' => static::$password ??= Hash::make('password'),
            'rol' => Rol::AuxiliarFarmacia,
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
}
