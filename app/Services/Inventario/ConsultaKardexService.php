<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\MovimientoKardex;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** RN-06-AC8: kardex paginado, filtros combinables, orden (created_at, id) y relaciones precargadas. */
final class ConsultaKardexService
{
    /** Relaciones que muestra MovimientoKardexResource (columnas mínimas, sin N+1). */
    public const RELACIONES = [
        'bodega:id,codigo,nombre',
        'producto:id,codigo,nombre',
        'lote:id,producto_id,codigo,fecha_vencimiento',
        'usuario:id,name',
    ];

    /**
     * @return LengthAwarePaginator<int, MovimientoKardex>
     */
    public function listar(FiltrosKardex $filtros): LengthAwarePaginator
    {
        return MovimientoKardex::query()
            ->with(self::RELACIONES)
            ->when($filtros->productoId !== null, fn ($q) => $q->where('producto_id', $filtros->productoId))
            ->when($filtros->loteId !== null, fn ($q) => $q->where('lote_id', $filtros->loteId))
            ->when($filtros->bodegaId !== null, fn ($q) => $q->where('bodega_id', $filtros->bodegaId))
            ->when($filtros->existenciaId !== null, fn ($q) => $q->where('existencia_id', $filtros->existenciaId))
            ->when($filtros->desde !== null, fn ($q) => $q->where('created_at', '>=', $filtros->desde))
            ->when($filtros->hastaExclusivo !== null, fn ($q) => $q->where('created_at', '<', $filtros->hastaExclusivo))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();
    }

    public function detalle(MovimientoKardex $movimiento): MovimientoKardex
    {
        return $movimiento->load(self::RELACIONES);
    }
}
