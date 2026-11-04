<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Models\Existencia;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** RN-01-AC9: existencias paginadas con filtros combinables y relaciones precargadas (sin N+1). */
final class ConsultaInventarioService
{
    /**
     * @return LengthAwarePaginator<int, Existencia>
     */
    public function listar(FiltrosInventario $filtros): LengthAwarePaginator
    {
        return Existencia::query()
            ->with([
                'lote:id,producto_id,codigo,fecha_vencimiento',
                'bodega:id,codigo,nombre',
                'producto:id,codigo,nombre',
            ])
            ->when($filtros->bodegaId !== null, fn ($q) => $q->where('bodega_id', $filtros->bodegaId))
            ->when($filtros->productoId !== null, fn ($q) => $q->where('producto_id', $filtros->productoId))
            ->when($filtros->loteId !== null, fn ($q) => $q->where('lote_id', $filtros->loteId))
            ->orderBy('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();
    }
}
