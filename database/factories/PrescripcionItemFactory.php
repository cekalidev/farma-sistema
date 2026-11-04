<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrescripcionItem>
 */
class PrescripcionItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prescripcion_id' => Prescripcion::factory(),
            'producto_id' => Producto::factory(),
            'cantidad' => fake()->numberBetween(1, 30),
            'indicaciones' => '1 cada 8 horas',
        ];
    }
}
