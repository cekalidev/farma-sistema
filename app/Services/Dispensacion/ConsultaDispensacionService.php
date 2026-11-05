<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Models\Dispensacion;
use App\Services\Auditoria\Bitacora;

/**
 * RN-10-AC4: detalle de una dispensación con el paciente anidado (PacienteResource lo enmascara
 * para el auditor). Como expone datos del paciente, queda en la bitácora (AC5).
 */
final class ConsultaDispensacionService
{
    public function __construct(private readonly Bitacora $bitacora) {}

    public function detalle(Dispensacion $dispensacion, int $usuarioId): Dispensacion
    {
        $dispensacion->load([...DispensacionService::relacionesRespuesta(), 'paciente']);

        $this->bitacora->consultaPermitida(
            $usuarioId,
            AccionAuditoria::DispensacionConsultar,
            [$dispensacion->paciente_id],
            EntidadAuditada::Dispensacion,
            $dispensacion->id,
        );

        return $dispensacion;
    }
}
