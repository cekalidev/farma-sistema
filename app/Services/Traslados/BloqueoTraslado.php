<?php

declare(strict_types=1);

namespace App\Services\Traslados;

use App\Enums\AccionTraslado;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Traslado;
use Illuminate\Support\Facades\DB;

/**
 * Primer paso de toda acción sobre un traslado (RN-07-AC3/AC11): relee la cabecera con FOR UPDATE
 * dentro de la transacción del llamador (primer bloqueo del orden global) y comprueba la
 * transición con TrasladoEstado::puede() sobre la fila bloqueada. Dos acciones simultáneas sobre el
 * mismo traslado se serializan aquí; la segunda ve el estado que dejó la primera y responde 409.
 */
final class BloqueoTraslado
{
    /**
     * @throws TransicionInvalida
     */
    public function bloquear(Traslado $traslado, AccionTraslado $accion): Traslado
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('BloqueoTraslado::bloquear exige una transacción abierta.');
        }

        $bloqueado = Traslado::query()->whereKey($traslado->id)->lockForUpdate()->firstOrFail();

        if (! $bloqueado->estado->puede($accion)) {
            throw TransicionInvalida::paraTraslado($bloqueado, $accion);
        }

        return $bloqueado;
    }
}
