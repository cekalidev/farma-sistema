<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\User;

/**
 * Bitácora de auditoría (RN-10-AC10). Consultar: auditoria.consultar (auditor, admin). No hay
 * create, update ni delete: solo escribe App\Services\Auditoria\Bitacora.
 */
final class AuditoriaAccesoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::AuditoriaConsultar);
    }
}
