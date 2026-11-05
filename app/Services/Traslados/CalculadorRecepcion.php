<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionTraslado;
use App\Enums\TrasladoEstado;
use App\Exceptions\Dominio\CantidadRecibidaExcede;

/**
 * RN-07-AC5/AC6/AC7: funciones puras de la recepción (sin BD), probadas de forma exhaustiva.
 */
final class CalculadorRecepcion
{
    /**
     * Unidades no recibidas de un ítem (la discrepancia; 0 = sin discrepancia).
     *
     * @throws CantidadRecibidaExcede si recibida > despachada.
     * @throws \InvalidArgumentException si alguna cantidad es negativa o la despachada es 0
     *                                   (la entrada HTTP y los CHECK ya lo impiden).
     */
    public static function faltante(int $trasladoItemId, int $despachada, int $recibida): int
    {
        if ($despachada <= 0 || $recibida < 0) {
            throw new \InvalidArgumentException('Cantidades de recepción fuera de rango.');
        }
        if ($recibida > $despachada) {
            throw CantidadRecibidaExcede::paraItem($trasladoItemId, $despachada, $recibida);
        }

        return $despachada - $recibida;
    }

    /**
     * Estado tras recibir: RECIBIDO si no falta nada en ningún ítem; si no, RECIBIDO_PARCIAL.
     *
     * @param  list<int>  $faltantes
     */
    public static function estadoResultante(array $faltantes): TrasladoEstado
    {
        if ($faltantes === []) {
            throw new \InvalidArgumentException('Un traslado sin ítems no se recibe.');
        }

        return TrasladoEstado::EnTransito->tras(
            AccionTraslado::Recibir,
            recepcionCompleta: array_sum($faltantes) === 0,
        );
    }
}
