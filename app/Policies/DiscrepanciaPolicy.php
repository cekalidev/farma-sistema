<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Exceptions\Dominio\SegregacionFunciones;
use App\Models\Discrepancia;
use App\Models\User;

/**
 * RN-07-AC12: resolver discrepancias = traslado.aprobar (regente); auxiliar y demás → 403
 * NO_AUTORIZADO. S-71 (segregación de RN-08): después, el regente no puede ser el solicitante del
 * traslado → LANZA SegregacionFunciones (403 con código propio). El rol va primero para no revelar
 * a otros roles quién solicitó. ResolucionDiscrepancia lo repite con la discrepancia bloqueada.
 *
 * Ojo: como DispensacionPolicy, `resolver` lanza en vez de devolver false; úsala solo con el
 * middleware `can:` de la ruta, no de forma informativa.
 */
final class DiscrepanciaPolicy
{
    /** @throws SegregacionFunciones */
    public function resolver(User $user, Discrepancia $discrepancia): bool
    {
        if (! $user->tieneHabilidad(Habilidad::TrasladoAprobar)) {
            return false;
        }

        $traslado = $discrepancia->item->traslado;
        if ($traslado->solicitado_por_id === $user->id) {
            throw SegregacionFunciones::paraTraslado($traslado->id, SegregacionFunciones::ACCION_RESOLVER_DISCREPANCIA);
        }

        return true;
    }
}
