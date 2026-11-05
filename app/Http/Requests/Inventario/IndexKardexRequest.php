<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventario;

use App\Http\Requests\Concerns\ReglasPaginacion;
use App\Models\MovimientoKardex;
use App\Providers\AppServiceProvider;
use App\Services\Inventario\FiltrosKardex;
use App\Support\Negocio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/kardex?producto_id=&lote_id=&bodega_id=&existencia_id=&desde=&hasta=&por_pagina=&page=
 * (RN-06-AC8). Mismas reglas de ids y paginación que GET /inventario (S-25, S-28).
 *
 * `desde` y `hasta` son fechas Y-m-d en la zona de negocio, ambas inclusivas: hasta=2026-11-04
 * incluye todo el 4 de noviembre en Bogotá.
 */
class IndexKardexRequest extends FormRequest
{
    use ReglasPaginacion;

    public const FECHA_MINIMA = '1900-01-01';

    public const FECHA_LIMITE = '2100-01-01';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MovimientoKardex::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = ['sometimes', 'numeric', 'integer', 'min:1', 'max:'.AppServiceProvider::ID_MAXIMO];
        $fecha = ['bail', 'sometimes', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-\d{2}$/', 'after_or_equal:'.self::FECHA_MINIMA, 'before:'.self::FECHA_LIMITE];

        return [
            'producto_id' => $id,
            'lote_id' => $id,
            'bodega_id' => $id,
            'existencia_id' => $id,
            'desde' => $fecha,
            // Solo se compara con `desde` si viene: sin él, Laravel intentaría parsear el texto 'desde'.
            'hasta' => $this->filled('desde') ? [...$fecha, 'after_or_equal:desde'] : $fecha,
            ...$this->reglasPaginacion(),
        ];
    }

    public function filtros(): FiltrosKardex
    {
        /** @var array{producto_id?: int|string, lote_id?: int|string, bodega_id?: int|string, existencia_id?: int|string, desde?: string, hasta?: string, por_pagina?: int|string} $validados */
        $validados = $this->validated();

        $entero = static fn (string $clave): ?int => isset($validados[$clave]) ? (int) $validados[$clave] : null;

        return new FiltrosKardex(
            productoId: $entero('producto_id'),
            loteId: $entero('lote_id'),
            bodegaId: $entero('bodega_id'),
            existenciaId: $entero('existencia_id'),
            desde: isset($validados['desde']) ? Negocio::inicioDelDia($validados['desde']) : null,
            hastaExclusivo: isset($validados['hasta']) ? Negocio::inicioDelDia($validados['hasta'], 1) : null,
            porPagina: $this->porPagina(),
        );
    }
}
