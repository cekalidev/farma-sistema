<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\User;

/**
 * Inventario (RN-01). Consultar: inventario.consultar (auxiliar, regente, auditor).
 * Registrar entradas: inventario.registrar (solo regente, S-17).
 */
final class ExistenciaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::InventarioConsultar);
    }

    public function registrarEntrada(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::InventarioRegistrar);
    }
}
