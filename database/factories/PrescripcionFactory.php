<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoPrescripcion;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\User;
use App\Support\Negocio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Por defecto: ACTIVA, emitida hace 1 día, vence en 30 días (vigente).
 *
 * @extends Factory<Prescripcion>
 */
class PrescripcionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hoy = Negocio::hoy();

        return [
            'paciente_id' => Paciente::factory(),
            'medico_id' => User::factory()->medico(),
            'fecha_emision' => $hoy->copy()->subDay()->toDateString(),
            'fecha_vencimiento' => $hoy->copy()->addDays(30)->toDateString(),
            'estado' => EstadoPrescripcion::Activa,
            'observaciones' => null,
        ];
    }

    public function anulada(): static
    {
        return $this->state(fn (array $attributes) => ['estado' => EstadoPrescripcion::Anulada]);
    }

    /** Venció ayer (S-05: con fecha = hoy sigue vigente). */
    public function vencida(): static
    {
        $hoy = Negocio::hoy();

        return $this->state(fn (array $attributes) => [
            'fecha_emision' => $hoy->copy()->subDays(40)->toDateString(),
            'fecha_vencimiento' => $hoy->copy()->subDay()->toDateString(),
        ]);
    }
}
