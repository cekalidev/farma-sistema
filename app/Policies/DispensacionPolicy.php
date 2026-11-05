<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Habilidad;
use App\Exceptions\Dominio\AutorizacionMismoUsuario;
use App\Models\Dispensacion;
use App\Models\User;

/**
 * Dispensación (RN-02). Crear y previsualizar: dispensacion.crear (auxiliar y regente).
 *
 * RN-05 autorizar/rechazar: dispensacion.autorizar (solo regente) → si no, 403 NO_AUTORIZADO
 * (RN-05-AC5). Después, regente distinto de quien la creó → si no, 403 AUTORIZACION_MISMO_USUARIO
 * (RN-05-AC4). El rol se evalúa primero para no revelar a otros roles quién creó la dispensación.
 * El servicio (AutorizacionDispensacion) repite la segunda comprobación y la BD la respalda con CHECK.
 *
 * RN-10-AC4 detalle (anida al paciente, S-77): quien consulta pacientes y además inventario
 * (auxiliar, regente, auditor). Médico y admin → 403.
 *
 * Ojo: `autorizar` y `rechazar` no devuelven false para el creador, LANZAN AutorizacionMismoUsuario
 * (403 con su propio código). Están pensadas solo para el middleware `can:` de las rutas; no las
 * uses con `can()`/`Gate::allows()` de forma informativa (p. ej. para pintar un botón o en un
 * Resource), porque la excepción escaparía en vez de dar false.
 */
final class DispensacionPolicy
{
    public function create(User $user): bool
    {
        return $user->tieneHabilidad(Habilidad::DispensacionCrear);
    }

    public function view(User $user, Dispensacion $dispensacion): bool
    {
        return $user->tieneHabilidad(Habilidad::PacienteConsultar)
            && $user->tieneHabilidad(Habilidad::InventarioConsultar);
    }

    /** @throws AutorizacionMismoUsuario */
    public function autorizar(User $user, Dispensacion $dispensacion): bool
    {
        return $this->segundoRegente($user, $dispensacion);
    }

    /** @throws AutorizacionMismoUsuario */
    public function rechazar(User $user, Dispensacion $dispensacion): bool
    {
        return $this->segundoRegente($user, $dispensacion);
    }

    /** @throws AutorizacionMismoUsuario */
    private function segundoRegente(User $user, Dispensacion $dispensacion): bool
    {
        if (! $user->tieneHabilidad(Habilidad::DispensacionAutorizar)) {
            return false;
        }

        if ($dispensacion->dispensado_por_id === $user->id) {
            throw AutorizacionMismoUsuario::paraDispensacion($dispensacion->id);
        }

        return true;
    }
}
