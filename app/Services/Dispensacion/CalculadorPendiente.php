<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\EstadoDispensacion;
use App\Exceptions\Dominio\CantidadExcedePrescrito;
use App\Models\PrescripcionItem;
use Illuminate\Support\Facades\DB;

/**
 * RN-04 tope acumulado: pendiente de un ítem prescrito = cantidad prescrita − suma de
 * dispensacion_items.cantidad de sus dispensaciones CONFIRMADA o PENDIENTE_AUTORIZACION (las
 * RECHAZADA no cuentan, RN-04-AC9).
 *
 * Solo lee. Para que el resultado sea fiable ante concurrencia (RN-04-AC8) el llamador debe tener
 * bloqueadas (FOR UPDATE) las filas de prescripcion_items: así la suma se lee después de que
 * confirme cualquier otra transacción que estuviera dispensando el mismo ítem.
 */
final class CalculadorPendiente
{
    /** Estados cuyos ítems consumen el tope de la prescripción. */
    public const ESTADOS_QUE_CUENTAN = [
        EstadoDispensacion::Confirmada,
        EstadoDispensacion::PendienteAutorizacion,
    ];

    public function pendiente(PrescripcionItem $item): int
    {
        return $this->pendientes([$item])[$item->id];
    }

    /**
     * Pendiente de varios ítems con una sola consulta.
     *
     * @param  list<PrescripcionItem>  $items
     * @return array<int, int> prescripcion_item_id => pendiente (nunca negativo)
     */
    public function pendientes(array $items): array
    {
        if ($items === []) {
            return [];
        }

        /** @var array<int|string, int|string|null> $dispensado */
        $dispensado = DB::table('dispensacion_items')
            ->join('dispensaciones', 'dispensaciones.id', '=', 'dispensacion_items.dispensacion_id')
            ->whereIn('dispensacion_items.prescripcion_item_id', array_map(static fn (PrescripcionItem $i): int => $i->id, $items))
            ->whereIn('dispensaciones.estado', array_map(static fn (EstadoDispensacion $e): string => $e->value, self::ESTADOS_QUE_CUENTAN))
            ->groupBy('dispensacion_items.prescripcion_item_id')
            ->selectRaw('dispensacion_items.prescripcion_item_id AS item_id, SUM(dispensacion_items.cantidad) AS dispensado')
            ->pluck('dispensado', 'item_id')
            ->all();

        $pendientes = [];
        foreach ($items as $item) {
            $pendientes[$item->id] = max(0, $item->cantidad - (int) ($dispensado[$item->id] ?? 0));
        }

        return $pendientes;
    }

    /**
     * Comprueba los ítems en orden de la petición y lanza con el primero que excede (como S-42).
     *
     * @param  list<ItemSolicitado>  $solicitados
     * @param  array<int, PrescripcionItem>  $prescritos  por id
     *
     * @throws CantidadExcedePrescrito
     */
    public function exigirDentroDelTope(array $solicitados, array $prescritos): void
    {
        $pendientes = $this->pendientes(array_values($prescritos));

        foreach ($solicitados as $solicitado) {
            $pendiente = $pendientes[$solicitado->prescripcionItemId]
                ?? throw new \LogicException("Ítem {$solicitado->prescripcionItemId} sin ítem prescrito.");

            if ($solicitado->cantidad > $pendiente) {
                throw CantidadExcedePrescrito::paraItem($solicitado->prescripcionItemId, $pendiente, $solicitado->cantidad);
            }
        }
    }
}
