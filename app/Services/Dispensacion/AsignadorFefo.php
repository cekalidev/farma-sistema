<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Existencia;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * RN-02: asignación FEFO (primero en vencer, primero en salir) de una cantidad sobre las
 * existencias de UN producto en UNA bodega.
 *
 * Función pura: no consulta ni escribe la BD y no muta la entrada (ni el arreglo ni los modelos).
 * El llamador le pasa las existencias ya bloqueadas (SalidaStock) o leídas (previsualización) con
 * la relación `lote` cargada; si no lo está, Eloquent la cargaría de forma perezosa.
 *
 * Reglas:
 *  - Orden: lotes.fecha_vencimiento ASC, lotes.id ASC (S-03); a igualdad (no ocurre: la terna es
 *    única), existencias.id ASC. El orden de entrada no importa.
 *  - Se ignoran existencias con cantidad 0 y lotes vencidos o que vencen hoy (S-01, Lote::estaVencido).
 *  - Todo o nada (S-04): si el stock vigente no alcanza, StockInsuficiente con `solicitado`,
 *    `disponible` (vigente) y `vencido_no_dispensable` (RN-01-AC5, RN-03-AC2, S-02).
 */
final class AsignadorFefo
{
    /**
     * @param  array<array-key, Existencia>  $existencias  del mismo producto y bodega
     * @return array<int, int> lote_id => cantidad, en orden FEFO (orden de consumo). Dentro de un
     *                         producto y una bodega cada lote tiene una sola existencia (UNIQUE de
     *                         la terna, RN-01-AC1), así que el lote identifica la existencia.
     *
     * @throws InvalidArgumentException si la cantidad no es positiva, las existencias mezclan
     *                                  productos o bodegas o repiten lote (fallo del llamador).
     * @throws StockInsuficiente si el stock vigente no alcanza (409).
     */
    public function asignar(array $existencias, int $cantidad, CarbonInterface $hoy): array
    {
        if ($cantidad <= 0) {
            throw new InvalidArgumentException("La cantidad a dispensar debe ser positiva; se recibió {$cantidad}.");
        }

        $this->exigirHomogeneas($existencias);

        $vigentes = [];
        $vencido = 0;

        foreach ($existencias as $existencia) {
            if ($existencia->cantidad <= 0) {
                continue;
            }
            if ($existencia->lote->estaVencido($hoy)) {
                $vencido += $existencia->cantidad;

                continue;
            }
            $vigentes[] = $existencia;
        }

        // usort ordena la copia local: la entrada del llamador no cambia.
        usort($vigentes, static fn (Existencia $a, Existencia $b): int => [
            $a->lote->fecha_vencimiento->toDateString(), $a->lote_id, $a->id,
        ] <=> [
            $b->lote->fecha_vencimiento->toDateString(), $b->lote_id, $b->id,
        ]);

        $disponible = array_sum(array_map(static fn (Existencia $e): int => $e->cantidad, $vigentes));

        if ($disponible < $cantidad) {
            throw StockInsuficiente::paraAsignacion($cantidad, $disponible, $vencido);
        }

        $tramos = [];
        $restante = $cantidad;

        foreach ($vigentes as $existencia) {
            $tomar = min($restante, $existencia->cantidad);
            $tramos[$existencia->lote_id] = $tomar;
            $restante -= $tomar;

            if ($restante === 0) {
                break;
            }
        }

        return $tramos;
    }

    /**
     * @param  array<array-key, Existencia>  $existencias
     */
    private function exigirHomogeneas(array $existencias): void
    {
        $lotes = array_map(static fn (Existencia $e): int => $e->lote_id, $existencias);
        if (count($lotes) !== count(array_unique($lotes))) {
            throw new InvalidArgumentException('AsignadorFefo recibe una sola existencia por lote.');
        }

        $productos = array_unique(array_map(static fn (Existencia $e): int => $e->producto_id, $existencias));
        $bodegas = array_unique(array_map(static fn (Existencia $e): int => $e->bodega_id, $existencias));

        if (count($productos) > 1 || count($bodegas) > 1) {
            throw new InvalidArgumentException('AsignadorFefo recibe existencias de un solo producto y una sola bodega.');
        }
    }
}
