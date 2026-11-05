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
 *
 * `$antesDelEstado` recibe la fila bloqueada y se ejecuta ANTES de comprobar la transición: así un
 * 403 que depende de la fila (RN-08 segregación) precede al 409 (S-57).
 */
final class BloqueoTraslado
{
    /**
     * @param  (\Closure(Traslado): void)|null  $antesDelEstado
     *
     * @throws TransicionInvalida
     */
    public function bloquear(Traslado $traslado, AccionTraslado $accion, ?\Closure $antesDelEstado = null): Traslado
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('BloqueoTraslado::bloquear exige una transacción abierta.');
        }

        $bloqueado = Traslado::query()->whereKey($traslado->id)->lockForUpdate()->firstOrFail();

        if ($antesDelEstado !== null) {
            $antesDelEstado($bloqueado);
        }

        if (! $bloqueado->estado->puede($accion)) {
            throw TransicionInvalida::paraTraslado($bloqueado, $accion);
        }

        return $bloqueado;
    }
}
