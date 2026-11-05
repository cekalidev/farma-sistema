<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\User;

/**
 * Dispensación (RN-02). Crear y previsualizar: dispensacion.crear (auxiliar y regente).
 * La autorización de control especial (RN-05, regente distinto del creador) llegará con esa RN.
 */
final class DispensacionPolicy
{
    public function create(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::DispensacionCrear);
    }
}
