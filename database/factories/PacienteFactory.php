<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoDocumento;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Pacientes SINTÉTICOS: nombres y documentos inventados.
 *
 * @extends Factory<Paciente>
 */
class PacienteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo_documento' => TipoDocumento::CedulaCiudadania,
            'numero_documento' => (string) fake()->unique()->numberBetween(900000000, 999999999),
            'nombres' => fake()->firstName(),
            'apellidos' => fake()->lastName().' '.fake()->lastName(),
            'fecha_nacimiento' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'telefono' => '300'.fake()->numerify('#######'),
            'direccion' => 'Calle '.fake()->numberBetween(1, 200).' # '.fake()->numberBetween(1, 99).'-'.fake()->numberBetween(1, 99),
            'correo' => fake()->unique()->userName().'@ejemplo.test',
        ];
    }
}
