<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\User;
use Database\Seeders\Concerns\GuardaProduccion;
use Illuminate\Database\Seeder;

/**
 * Un usuario por rol, salvo regente_farmacia con dos (S-19: RN-05 y RN-08 exigen un
 * segundo regente). Contraseña SOLO de desarrollo: `Farma2026!`.
 */
class UsuariosSeeder extends Seeder
{
    use GuardaProduccion;

    public const PASSWORD_DESARROLLO = 'Farma2026!';

    /** @var array<string, array{string, Rol}> correo => [nombre, rol] */
    public const USUARIOS = [
        'auxiliar@farma.test' => ['Auxiliar Demo', Rol::AuxiliarFarmacia],
        'regente@farma.test' => ['Regente Demo', Rol::RegenteFarmacia],
        'regente2@farma.test' => ['Regente Demo Dos', Rol::RegenteFarmacia],
        'medico@farma.test' => ['Médico Demo', Rol::Medico],
        'auditor@farma.test' => ['Auditor Demo', Rol::Auditor],
        'admin@farma.test' => ['Admin Demo', Rol::Admin],
    ];

    public function run(): void
    {
        // Guarda propia: `db:seed --class=UsuariosSeeder` no pasa por DatabaseSeeder.
        $this->abortarEnProduccionSinPermiso();

        foreach (self::USUARIOS as $correo => [$nombre, $rol]) {
            // Busca sin distinguir mayúsculas (índice lower(email)): una fila previa con otra
            // capitalización se actualiza en vez de chocar con el índice único.
            User::query()->porCorreo($correo)->firstOrNew()
                ->fill([
                    'name' => $nombre,
                    'email' => $correo,
                    'password' => self::PASSWORD_DESARROLLO,
                    'rol' => $rol,
                ])
                ->save();
        }
    }
}
