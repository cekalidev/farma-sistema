<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoAlertaVencimiento;
use App\Models\Existencia;
use App\Models\StockMinimo;
use App\Support\Negocio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;

/**
 * RN-11: alertas de vencimiento y de stock mínimo. Solo lectura (sin transacción ni bloqueos);
 * "hoy" llega como parámetro para que los tests congelen la fecha. El filtrado, el orden y la
 * paginación se hacen en SQL; PHP solo clasifica las filas de la página con ClasificadorVencimiento.
 */
final class ConsultaAlertasService
{
    /** Relaciones que muestran los resources de alertas (columnas mínimas, sin N+1). */
    private const RELACIONES_VENCIMIENTO = [
        'lote:id,producto_id,codigo,fecha_vencimiento',
        'bodega:id,codigo,nombre',
        'producto:id,codigo,nombre',
    ];

    private const RELACIONES_STOCK_MINIMO = [
        'bodega:id,codigo,nombre',
        'producto:id,codigo,nombre',
    ];

    public function umbralDias(): int
    {
        return max(0, Config::integer('farma.dias_alerta_vencimiento'));
    }

    /**
     * RN-11-AC1..AC4: existencias con cantidad > 0 de lotes vencidos (S-01) o que vencen en
     * ≤ umbral días; orden fecha_vencimiento ASC, lote id, existencia id.
     *
     * @return LengthAwarePaginator<int, AlertaVencimiento>
     */
    public function vencimientos(FiltrosAlertaVencimiento $filtros, CarbonInterface $hoy): LengthAwarePaginator
    {
        $umbral = $this->umbralDias();
        $hoyFecha = self::fechaNegocio($hoy);
        $limite = self::fechaNegocio($hoy)->addDays($umbral)->toDateString();

        $pagina = Existencia::query()
            ->select('existencias.*')
            ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
            ->with(self::RELACIONES_VENCIMIENTO)
            ->where('existencias.cantidad', '>', 0)
            ->where('lotes.fecha_vencimiento', '<=', match ($filtros->tipo) {
                TipoAlertaVencimiento::Vencido => $hoyFecha->toDateString(),
                default => $limite,
            })
            ->when($filtros->tipo === TipoAlertaVencimiento::PorVencer, fn ($q) => $q->where('lotes.fecha_vencimiento', '>', $hoyFecha->toDateString()))
            ->when($filtros->bodegaId !== null, fn ($q) => $q->where('existencias.bodega_id', $filtros->bodegaId))
            ->when($filtros->productoId !== null, fn ($q) => $q->where('existencias.producto_id', $filtros->productoId))
            ->orderBy('lotes.fecha_vencimiento')
            ->orderBy('lotes.id')
            ->orderBy('existencias.id')
            ->paginate($filtros->porPagina)
            ->withQueryString();

        $filas = $pagina->getCollection()->map(function (Existencia $existencia) use ($hoy, $umbral): AlertaVencimiento {
            $clasificacion = ClasificadorVencimiento::clasificar($existencia->lote->fecha_vencimiento, $hoy, $umbral)
                ?? throw new \LogicException('La consulta de vencimientos devolvió un lote fuera del umbral.');

            return new AlertaVencimiento($existencia, $clasificacion['tipo'], $clasificacion['dias_restantes']);
        });

        /** @var LengthAwarePaginator<int, AlertaVencimiento> $resultado */
        $resultado = $pagina->setCollection($filas);

        return $resultado;
    }

    /**
     * RN-11-AC5/AC6 (S-15): por cada stock mínimo, `actual` = suma de existencias de lotes no
     * vencidos del producto en la bodega (0 si no hay); solo los que quedan estrictamente por debajo.
     * Una consulta agregada (subconsulta correlacionada por el índice único de existencias);
     * orden: mayor faltante primero, luego bodega, producto e id.
     *
     * @return LengthAwarePaginator<int, AlertaStockMinimo>
     */
    public function stockMinimo(FiltrosAlertaStockMinimo $filtros, CarbonInterface $hoy): LengthAwarePaginator
    {
        $hoyFecha = self::fechaNegocio($hoy)->toDateString();

        $actual = fn (Builder $q) => $q->from('existencias')
            ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
            ->selectRaw('COALESCE(SUM(existencias.cantidad), 0)')
            ->whereColumn('existencias.bodega_id', 'stock_minimos.bodega_id')
            ->whereColumn('existencias.producto_id', 'stock_minimos.producto_id')
            ->where('lotes.fecha_vencimiento', '>', $hoyFecha);

        $conActual = StockMinimo::query()
            ->select('stock_minimos.*')
            ->selectSub($actual, 'actual')
            ->when($filtros->bodegaId !== null, fn ($q) => $q->where('stock_minimos.bodega_id', $filtros->bodegaId))
            ->when($filtros->productoId !== null, fn ($q) => $q->where('stock_minimos.producto_id', $filtros->productoId));

        $pagina = StockMinimo::query()
            ->fromSub($conActual, 'stock_minimos')
            ->with(self::RELACIONES_STOCK_MINIMO)
            ->whereColumn('actual', '<', 'cantidad_minima')
            ->orderByRaw('(cantidad_minima - actual) DESC')
            ->orderBy('bodega_id')
            ->orderBy('producto_id')
            ->orderBy('id')
            ->paginate($filtros->porPagina)
            ->withQueryString();

        $filas = $pagina->getCollection()->map(
            fn (StockMinimo $minimo): AlertaStockMinimo => new AlertaStockMinimo($minimo, self::entero($minimo->getAttribute('actual'))),
        );

        /** @var LengthAwarePaginator<int, AlertaStockMinimo> $resultado */
        $resultado = $pagina->setCollection($filas);

        return $resultado;
    }

    /** Fecha calendario de `$hoy` en la zona de negocio (misma noción que Lote::estaVencido). */
    private static function fechaNegocio(CarbonInterface $hoy): CarbonImmutable
    {
        return CarbonImmutable::parse($hoy->copy()->setTimezone(Negocio::zonaHoraria())->toDateString(), 'UTC');
    }

    private static function entero(mixed $valor): int
    {
        if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
            return (int) $valor;
        }

        throw new \UnexpectedValueException('Suma de stock no entera.');
    }
}
