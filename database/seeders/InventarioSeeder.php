<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\StockMinimo;
use Illuminate\Database\Seeder;

/**
 * Lotes con vencimiento relativo a hoy (Bogotá), existencias y stock mínimo.
 * Incluye lotes vencidos (−20 y −3 días) y lotes que vencen en < 30 días (+10, +25).
 *
 * TEMPORAL hasta RN-06: la carga inicial escribe la cantidad directamente con forceFill
 * (Existencia no admite `cantidad` en asignación masiva) porque el kardex y el servicio de
 * entradas aún no existen. Cuando existan, este seeder deberá pasar por ese servicio.
 */
class InventarioSeeder extends Seeder
{
    /**
     * producto => [[sufijo_lote, días hasta vencer, [bodega => cantidad]]].
     *
     * @var array<string, list<array{string, int, array<string, int>}>>
     */
    private const LOTES = [
        'ACET500' => [
            ['A', -20, ['FC' => 40]],                       // vencido
            ['B', 25, ['FC' => 120, 'FU' => 60]],           // vence en < 30 días
            ['C', 400, ['FC' => 300, 'FU' => 100, 'BH' => 500]],
        ],
        'AMOX500' => [
            ['A', 10, ['FC' => 30, 'BH' => 20]],            // vence en < 30 días
            ['B', 210, ['FC' => 150, 'FU' => 40, 'BH' => 200]],
        ],
        'IBU400' => [
            ['A', 60, ['FC' => 80, 'FU' => 50]],
            ['B', 365, ['FC' => 200, 'BH' => 300]],
            ['C', 540, ['BH' => 150]],
        ],
        'LOS50' => [
            ['A', -3, ['FC' => 15]],                        // vencido
            ['B', 150, ['FC' => 90, 'BH' => 120]],
        ],
        'OME20' => [
            ['A', 85, ['FC' => 60, 'FU' => 30]],            // dentro de la alerta de 90 días
            ['B', 300, ['FC' => 180, 'BH' => 240]],
        ],
        'MORF10' => [
            ['A', 45, ['FC' => 12, 'FU' => 8]],
            ['B', 240, ['FC' => 25, 'FU' => 10]],
        ],
    ];

    /** @var array<string, array<string, int>> producto => [bodega => mínimo] */
    private const MINIMOS = [
        'ACET500' => ['FC' => 200, 'FU' => 80, 'BH' => 300],
        'AMOX500' => ['FC' => 100, 'FU' => 50, 'BH' => 100],
        'IBU400' => ['FC' => 150, 'FU' => 60, 'BH' => 200],
        'LOS50' => ['FC' => 120, 'FU' => 20, 'BH' => 100],
        'OME20' => ['FC' => 100, 'FU' => 40, 'BH' => 150],
        'MORF10' => ['FC' => 20, 'FU' => 10, 'BH' => 5],
    ];

    public function run(): void
    {
        $hoy = now((string) config('farma.zona_horaria'))->startOfDay();
        $bodegas = Bodega::query()->pluck('id', 'codigo');
        $productos = Producto::query()->pluck('id', 'codigo');

        foreach (self::LOTES as $codigoProducto => $lotes) {
            $productoId = $productos[$codigoProducto];

            foreach ($lotes as [$sufijo, $dias, $cantidades]) {
                $lote = Lote::query()->updateOrCreate(
                    ['producto_id' => $productoId, 'codigo' => $codigoProducto.'-'.$sufijo],
                    ['fecha_vencimiento' => $hoy->copy()->addDays($dias)->toDateString()],
                );

                foreach ($cantidades as $codigoBodega => $cantidad) {
                    Existencia::query()
                        ->firstOrNew(['bodega_id' => $bodegas[$codigoBodega], 'producto_id' => $productoId, 'lote_id' => $lote->id])
                        ->forceFill(['cantidad' => $cantidad])
                        ->save();
                }
            }
        }

        foreach (self::MINIMOS as $codigoProducto => $porBodega) {
            foreach ($porBodega as $codigoBodega => $minimo) {
                StockMinimo::query()->updateOrCreate(
                    ['bodega_id' => $bodegas[$codigoBodega], 'producto_id' => $productos[$codigoProducto]],
                    ['cantidad_minima' => $minimo],
                );
            }
        }
    }
}
