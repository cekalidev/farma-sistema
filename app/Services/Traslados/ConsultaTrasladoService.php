<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Models\Traslado;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** RN-07-AC13: listado paginado con filtros combinables y detalle con ítems y discrepancias. */
final class ConsultaTrasladoService
{
    /**
     * Más recientes primero.
     *
     * @return LengthAwarePaginator<int, Traslado>
     */
    public function listar(FiltrosTraslado $filtros): LengthAwarePaginator
    {
        return Traslado::query()
            ->with(TrasladoService::relacionesRespuesta())
            ->when($filtros->estado !== null, fn (Builder $q) => $q->where('estado', $filtros->estado))
            ->when($filtros->bodegaOrigenId !== null, fn (Builder $q) => $q->where('bodega_origen_id', $filtros->bodegaOrigenId))
            ->when($filtros->bodegaDestinoId !== null, fn (Builder $q) => $q->where('bodega_destino_id', $filtros->bodegaDestinoId))
            ->when($filtros->bodegaId !== null, fn (Builder $q) => $q->where(
                fn (Builder $o) => $o->where('bodega_origen_id', $filtros->bodegaId)->orWhere('bodega_destino_id', $filtros->bodegaId),
            ))
            ->orderByDesc('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();
    }

    public function detalle(Traslado $traslado): Traslado
    {
        return $traslado->load(TrasladoService::relacionesRespuesta());
    }
}
