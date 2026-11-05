<?php

declare(strict_types=1);

namespace App\Services\Auditoria;

use App\Models\AuditoriaAcceso;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** RN-10-AC10: bitácora paginada, más reciente primero (created_at, id descendentes). */
final class ConsultaAuditoriaService
{
    /**
     * @return LengthAwarePaginator<int, AuditoriaAcceso>
     */
    public function listar(FiltrosAuditoria $filtros): LengthAwarePaginator
    {
        return AuditoriaAcceso::query()
            ->with('usuario:id,name,rol')
            ->when($filtros->usuarioId !== null, fn ($q) => $q->where('usuario_id', $filtros->usuarioId))
            ->when($filtros->pacienteId !== null, fn ($q) => $q->where('paciente_id', $filtros->pacienteId))
            ->when($filtros->resultado !== null, fn ($q) => $q->where('resultado', $filtros->resultado))
            ->when($filtros->accion !== null, fn ($q) => $q->where('accion', $filtros->accion))
            ->when($filtros->desde !== null, fn ($q) => $q->where('created_at', '>=', $filtros->desde))
            ->when($filtros->hastaExclusivo !== null, fn ($q) => $q->where('created_at', '<', $filtros->hastaExclusivo))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();
    }
}
