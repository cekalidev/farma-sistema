<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\StockMinimo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMinimo>
 */
class StockMinimoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bodega_id' => Bodega::factory(),
            'producto_id' => Producto::factory(),
            'cantidad_minima' => fake()->numberBetween(5, 50),
        ];
    }
}
