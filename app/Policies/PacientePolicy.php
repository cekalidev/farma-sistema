<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Models\Paciente;
use App\Models\User;

/**
 * Pacientes (RN-10). Consultar (detalle, búsqueda, prescripciones): paciente.consultar
 * (auxiliar, regente, médico; auditor enmascarado). admin → 403 (RN-10-AC2, S-13). El enmascarado
 * no se decide aquí sino en PacienteResource.
 */
final class PacientePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::PacienteConsultar);
    }

    public function view(User $user, Paciente $paciente): bool
    {
        return $this->viewAny($user);
    }
}
