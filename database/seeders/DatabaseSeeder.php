<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\GuardaProduccion;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

        // Atómica: si falla a medias (p. ej. en el arranque del contenedor), no quedan usuarios sin
        // catálogo ni inventario, y el siguiente arranque vuelve a sembrar (tabla users vacía).
        // Las transacciones internas (InventarioSeeder) pasan a ser savepoints.
        DB::transaction(fn () => $this->call([
            UsuariosSeeder::class,
            CatalogoSeeder::class,
            InventarioSeeder::class,
            PacientesSeeder::class,
        ]));
    }
}
