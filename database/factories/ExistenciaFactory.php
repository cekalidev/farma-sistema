<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Existencia>
 */
class ExistenciaFactory extends Factory
{
    /**
     * producto_id se deriva del lote para respetar la FK compuesta (RN-01-AC3).
     * `cantidad` no es fillable en Existencia, pero las factories crean el modelo sin guarda
     * (Model::unguarded), así que los tests sí pueden fijar el stock inicial.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bodega_id' => Bodega::factory(),
            'lote_id' => Lote::factory(),
            'producto_id' => fn (array $attributes) => Lote::query()->findOrFail($attributes['lote_id'])->producto_id,
            'cantidad' => fake()->numberBetween(10, 200),
        ];
    }

    /** Usa un lote existente (y su producto). */
    public function paraLote(Lote $lote): static
    {
        return $this->state(fn (array $attributes) => [
            'lote_id' => $lote->id,
            'producto_id' => $lote->producto_id,
        ]);
    }
}
