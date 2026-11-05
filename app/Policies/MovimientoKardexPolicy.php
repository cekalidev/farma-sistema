<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\MovimientoKardex;
use App\Models\User;

/**
 * Kardex (RN-06). Consultar: inventario.consultar (auxiliar, regente, auditor). No hay create,
 * update ni delete: los movimientos solo los escribe RegistradorKardex desde un servicio.
 */
final class MovimientoKardexPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::InventarioConsultar);
    }

    public function view(User $user, MovimientoKardex $movimiento): bool
    {
        return $this->viewAny($user);
    }
}
