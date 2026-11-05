<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\Discrepancia;
use App\Models\User;

/** RN-07-AC12: resolver discrepancias = traslado.aprobar (regente); auxiliar y demás → 403. */
final class DiscrepanciaPolicy
{
    public function resolver(User $user, Discrepancia $discrepancia): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoAprobar);
    }
}
