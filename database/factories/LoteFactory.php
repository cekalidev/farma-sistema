<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Lote>
 */
class LoteFactory extends Factory
{
    /**
     * Por defecto vigente: vence entre 120 y 720 días después de hoy (Bogotá).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'producto_id' => Producto::factory(),
            'codigo' => 'L-'.fake()->unique()->bothify('????-#####'),
            'fecha_vencimiento' => self::hoy()->addDays(fake()->numberBetween(120, 720))->toDateString(),
        ];
    }

    /** Vence N días después de hoy (negativo = en el pasado; 0 = hoy, vencido según S-01). */
    public function venceEn(int $dias): static
    {
        return $this->state(fn (array $attributes) => [
            'fecha_vencimiento' => self::hoy()->addDays($dias)->toDateString(),
        ]);
    }

    public function vencido(): static
    {
        return $this->venceEn(-30);
    }

    private static function hoy(): Carbon
    {
        return now((string) config('farma.zona_horaria'))->startOfDay();
    }
}
