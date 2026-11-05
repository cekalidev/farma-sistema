<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Exceptions\Dominio\SegregacionFunciones;
use App\Models\Traslado;
use App\Models\User;

/**
 * Traslados (RN-07, matriz de roles). 403 NO_AUTORIZADO si devuelve false.
 *
 * - Consultar: traslado.consultar (auxiliar, regente, auditor; RN-07-AC13).
 * - Crear, despachar, recibir: traslado.operar (auxiliar, regente).
 * - Editar ítems y solicitar: traslado.operar y ser el creador (S-08; RN-08-AC2: otro usuario → 403).
 * - Aprobar: traslado.aprobar (regente) → si no, 403 NO_AUTORIZADO (RN-08-AC4). Después, RN-08-AC1:
 *   el aprobador no puede ser el solicitante → LANZA SegregacionFunciones (403 con código propio;
 *   el rol va primero para no revelar a otros roles quién solicitó). TrasladoService::aprobar lo
 *   repite sobre la fila bloqueada y la BD lo respalda con CHECK. Como en DispensacionPolicy, úsala
 *   solo con el middleware `can:` de la ruta, no de forma informativa.
 * - Anular: traslado.anular y ser el creador o regente (traslado.aprobar).
 *
 * El estado NO se mira aquí (lo decide el servicio con la fila bloqueada → 409), así que el 403
 * precede siempre al 409.
 */
final class TrasladoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoConsultar);
    }

    public function view(User $user, Traslado $traslado): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoConsultar);
    }

    public function create(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoOperar);
    }

    public function editarItems(User $user, Traslado $traslado): bool
    {
        return $this->esCreadorOperador($user, $traslado);
    }

    public function solicitar(User $user, Traslado $traslado): bool
    {
        return $this->esCreadorOperador($user, $traslado);
    }

    /** @throws SegregacionFunciones */
    public function aprobar(User $user, Traslado $traslado): bool
    {
        if (! $user->tieneHabilidad(Habilidad::TrasladoAprobar)) {
            return false;
        }

        if ($traslado->solicitado_por_id === $user->id) {
            throw SegregacionFunciones::paraTraslado($traslado->id, SegregacionFunciones::ACCION_APROBAR);
        }

        return true;
    }

    public function despachar(User $user, Traslado $traslado): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoOperar);
    }

    public function recibir(User $user, Traslado $traslado): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoOperar);
    }

    public function anular(User $user, Traslado $traslado): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoAnular)
            && ($traslado->creado_por_id === $user->id || $user->tieneHabilidad(Habilidad::TrasladoAprobar));
    }

    private function esCreadorOperador(User $user, Traslado $traslado): bool
    {
        return $user->tieneHabilidad(Habilidad::TrasladoOperar) && $traslado->creado_por_id === $user->id;
    }
}
