<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Models\Dispensacion;
use App\Services\Auditoria\Bitacora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * RN-10-AC4: detalle de una dispensación con el paciente anidado (PacienteResource lo enmascara
 * para el auditor). Como expone datos del paciente, queda en la bitácora (AC5).
 *
 * S-89: listado paginado (panel de pendientes del regente). No carga ni expone al paciente (solo
 * `paciente_id`), así que no escribe en la bitácora; tampoco carga los tramos FEFO ni sus
 * movimientos (`detalles`), que siguen en el detalle.
 */
final class ConsultaDispensacionService
{
    public function __construct(private readonly Bitacora $bitacora) {}

    /** Relaciones del listado: tres consultas fijas por página (ítems, productos, creadores). */
    public const RELACIONES_LISTADO = [
        'items.producto:id,codigo,nombre,control_especial',
        'dispensadoPor:id,name',
    ];

    /**
     * Más antiguas primero (la cola de pendientes se atiende por orden de llegada); desempate por id.
     *
     * @return LengthAwarePaginator<int, Dispensacion>
     */
    public function listar(FiltrosDispensacion $filtros): LengthAwarePaginator
    {
        return Dispensacion::query()
            ->with(self::RELACIONES_LISTADO)
            ->when($filtros->estado !== null, fn (Builder $q) => $q->where('estado', $filtros->estado))
            ->when($filtros->bodegaId !== null, fn (Builder $q) => $q->where('bodega_id', $filtros->bodegaId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();
    }

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
