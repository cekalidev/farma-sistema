<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Producto;
use Illuminate\Database\Seeder;

class CatalogoSeeder extends Seeder
{
    /** @var array<string, string> */
    public const BODEGAS = [
        'FC' => 'Farmacia Central',
        'FU' => 'Farmacia Urgencias',
        'BH' => 'Bodega Hospitalización',
    ];

    /** @var array<string, array{nombre: string, presentacion: string, unidad_medida: string, control_especial: bool}> */
    public const PRODUCTOS = [
        'ACET500' => ['nombre' => 'Acetaminofén 500 mg', 'presentacion' => 'Tableta', 'unidad_medida' => 'tableta', 'control_especial' => false],
        'AMOX500' => ['nombre' => 'Amoxicilina 500 mg', 'presentacion' => 'Cápsula', 'unidad_medida' => 'cápsula', 'control_especial' => false],
        'IBU400' => ['nombre' => 'Ibuprofeno 400 mg', 'presentacion' => 'Tableta', 'unidad_medida' => 'tableta', 'control_especial' => false],
        'LOS50' => ['nombre' => 'Losartán 50 mg', 'presentacion' => 'Tableta', 'unidad_medida' => 'tableta', 'control_especial' => false],
        'OME20' => ['nombre' => 'Omeprazol 20 mg', 'presentacion' => 'Cápsula', 'unidad_medida' => 'cápsula', 'control_especial' => false],
        'MORF10' => ['nombre' => 'Morfina 10 mg/mL', 'presentacion' => 'Ampolla 1 mL', 'unidad_medida' => 'ampolla', 'control_especial' => true],
    ];

    public function run(): void
    {
        foreach (self::BODEGAS as $codigo => $nombre) {
            Bodega::query()->updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'activa' => true]);
        }

        foreach (self::PRODUCTOS as $codigo => $datos) {
            Producto::query()->updateOrCreate(['codigo' => $codigo], $datos + ['activo' => true]);
        }
    }
}
