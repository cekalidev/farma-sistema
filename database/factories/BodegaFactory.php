<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bodega>
 */
class BodegaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'codigo' => 'BOD-'.$n,
            'nombre' => 'Bodega prueba '.$n,
            'activa' => true,
        ];
    }
}
