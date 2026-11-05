<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\DocumentoOrigen;
use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Dispensacion;
use App\Models\DispensacionDetalle;
use App\Models\Existencia;
use App\Services\Inventario\RegistradorKardex;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * RN-02 + RN-03 ("cómo se defiende"): salida de stock FEFO de una dispensación como unidad
 * transaccional, todo o nada (S-04).
 *
 * 1. Bloquea con FOR UPDATE TODAS las existencias de los productos de la dispensación en su bodega,
 *    en un único orden global por existencias.id (orden determinista: dos dispensaciones que
 *    comparten existencias las bloquean en el mismo orden y no se interbloquean).
 *    Va siempre después de bloquear los prescripcion_items (orden global prescripcion_items →
 *    existencias, ver DispensacionService); quien la llame (RN-05 al autorizar) debe respetarlo.
 * 2. Recalcula FEFO con AsignadorFefo sobre las filas ya bloqueadas (nunca con valores leídos antes).
 * 3. Por cada tramo: RegistradorKardex (SALIDA_DISPENSACION, documento dispensación + id) y un
 *    dispensacion_detalle ligado a su movimiento.
 *
 * Abre su propia transacción (o un savepoint si el llamador ya tiene una): ante cualquier error no
 * queda stock descontado, movimiento ni detalle. La usará también la autorización de RN-05.
 */
final class SalidaStock
{
    public function __construct(
        private readonly AsignadorFefo $asignador,
        private readonly RegistradorKardex $kardex,
    ) {}

    /**
     * @return list<DispensacionDetalle> en orden de descuento (= orden del kardex)
     *
     * @throws StockInsuficiente si algún producto no tiene stock vigente suficiente (409, sin cambios).
     */
    public function descontar(Dispensacion $dispensacion, int $usuarioId, CarbonInterface $hoy): array
    {
        return DB::transaction(function () use ($dispensacion, $usuarioId, $hoy): array {
            if ($dispensacion->detalles()->exists()) {
                throw new \LogicException("La dispensación {$dispensacion->id} ya descontó stock.");
            }

            $items = $dispensacion->items()->get();

            $existencias = Existencia::query()
                ->where('bodega_id', $dispensacion->bodega_id)
                ->whereIn('producto_id', $items->pluck('producto_id')->unique()->values()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->with('lote')
                ->get();

            $porProducto = $existencias->groupBy('producto_id');

            // Primero se asignan TODOS los ítems: si uno no alcanza, falla antes de escribir nada.
            $asignaciones = [];
            foreach ($items as $item) {
                try {
                    $asignaciones[$item->id] = $this->asignador->asignar(
                        $porProducto->get($item->producto_id)?->all() ?? [],
                        $item->cantidad,
                        $hoy,
                    );
                } catch (StockInsuficiente $e) {
                    throw $e->conContexto([
                        'producto_id' => $item->producto_id,
                        'bodega_id' => $dispensacion->bodega_id,
                        'prescripcion_item_id' => $item->prescripcion_item_id,
                    ]);
                }
            }

            $detalles = [];

            foreach ($items as $item) {
                $porLote = $porProducto->get($item->producto_id)?->keyBy('lote_id');

                foreach ($asignaciones[$item->id] as $loteId => $cantidad) {
                    $existencia = $porLote?->get($loteId) ?? throw new \LogicException('Tramo FEFO sobre una existencia no bloqueada.');

                    $movimiento = $this->kardex->registrar(
                        TipoMovimiento::SalidaDispensacion,
                        $existencia,
                        -$cantidad,
                        $usuarioId,
                        documentoTipo: DocumentoOrigen::Dispensacion,
                        documentoId: $dispensacion->id,
                    );

                    $detalle = new DispensacionDetalle;
                    $detalle->forceFill([
                        'dispensacion_id' => $dispensacion->id,
                        'dispensacion_item_id' => $item->id,
                        'existencia_id' => $existencia->id,
                        'bodega_id' => $existencia->bodega_id,
                        'producto_id' => $existencia->producto_id,
                        'lote_id' => $existencia->lote_id,
                        'movimiento_kardex_id' => $movimiento->id,
                        'cantidad' => $cantidad,
                    ])->save();

                    $detalles[] = $detalle;
                }
            }

            return $detalles;
        });
    }
}
