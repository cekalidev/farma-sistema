<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoPrescripcion;
use App\Enums\TipoDocumento;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Support\Negocio;
use Illuminate\Database\Seeder;

/**
 * 3 pacientes SINTÉTICOS (documentos y nombres inventados), cada uno con una
 * prescripción vigente; la del tercero incluye el producto de control especial.
 */
class PacientesSeeder extends Seeder
{
    /**
     * @var list<array{paciente: array<string, string>, items: array<string, int>}>
     */
    private const PACIENTES = [
        [
            'paciente' => [
                'numero_documento' => '9900000001', 'nombres' => 'Ana Sintética', 'apellidos' => 'Prueba Uno',
                'fecha_nacimiento' => '1985-04-12', 'telefono' => '3000000001', 'direccion' => 'Calle Ficticia 1 # 2-3',
                'correo' => 'paciente1@ejemplo.test',
            ],
            'items' => ['ACET500' => 30, 'AMOX500' => 21],
        ],
        [
            'paciente' => [
                'numero_documento' => '9900000002', 'nombres' => 'Bruno Ficticio', 'apellidos' => 'Prueba Dos',
                'fecha_nacimiento' => '1972-11-30', 'telefono' => '3000000002', 'direccion' => 'Carrera Inventada 4 # 5-6',
                'correo' => 'paciente2@ejemplo.test',
            ],
            'items' => ['LOS50' => 60, 'OME20' => 30],
        ],
        [
            'paciente' => [
                'numero_documento' => '9900000003', 'nombres' => 'Carla Demo', 'apellidos' => 'Prueba Tres',
                'fecha_nacimiento' => '1990-07-05', 'telefono' => '3000000003', 'direccion' => 'Avenida Simulada 7 # 8-9',
                'correo' => 'paciente3@ejemplo.test',
            ],
            'items' => ['MORF10' => 6, 'IBU400' => 20],
        ],
    ];

    public function run(): void
    {
        $hoy = Negocio::hoy();
        $medico = User::query()->porCorreo('medico@farma.test')->firstOrFail();
        $productos = Producto::query()->pluck('id', 'codigo');

        foreach (self::PACIENTES as $datos) {
            $paciente = Paciente::query()->updateOrCreate(
                ['tipo_documento' => TipoDocumento::CedulaCiudadania, 'numero_documento' => $datos['paciente']['numero_documento']],
                $datos['paciente'],
            );

            $prescripcion = Prescripcion::query()->firstOrCreate(
                ['paciente_id' => $paciente->id, 'medico_id' => $medico->id, 'estado' => EstadoPrescripcion::Activa],
                [
                    'fecha_emision' => $hoy->copy()->subDays(2)->toDateString(),
                    'fecha_vencimiento' => $hoy->copy()->addDays(28)->toDateString(),
                    'observaciones' => 'Prescripción sintética de demostración.',
                ],
            );

            foreach ($datos['items'] as $codigoProducto => $cantidad) {
                PrescripcionItem::query()->updateOrCreate(
                    ['prescripcion_id' => $prescripcion->id, 'producto_id' => $productos[$codigoProducto]],
                    ['cantidad' => $cantidad, 'indicaciones' => 'Según indicación médica.'],
                );
            }
        }
    }
}
