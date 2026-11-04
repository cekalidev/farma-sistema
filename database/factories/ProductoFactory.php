<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'codigo' => 'PRD-'.$n,
            'nombre' => 'Producto prueba '.$n,
            'presentacion' => 'Tableta 500 mg',
            'unidad_medida' => 'tableta',
            'control_especial' => false,
            'activo' => true,
        ];
    }

    public function controlEspecial(): static
    {
        return $this->state(fn (array $attributes) => ['control_especial' => true]);
    }
}
