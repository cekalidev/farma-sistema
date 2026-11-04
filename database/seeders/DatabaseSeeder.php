<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\GuardaProduccion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * BASE-AC6: datos SINTÉTICOS repetibles con `php artisan migrate:fresh --seed`.
 * Fechas relativas a hoy en America/Bogota.
 */
class DatabaseSeeder extends Seeder
{
    use GuardaProduccion;
    use WithoutModelEvents;

    public function run(): void
    {
        // Antes de cualquier escritura (también la valida UsuariosSeeder por sí mismo).
        $this->abortarEnProduccionSinPermiso();

        $this->call([
            UsuariosSeeder::class,
            CatalogoSeeder::class,
            InventarioSeeder::class,
            PacientesSeeder::class,
        ]);
    }
}
