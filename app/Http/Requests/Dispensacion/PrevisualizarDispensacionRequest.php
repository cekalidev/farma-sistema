<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispensacion;

/**
 * POST /api/v1/dispensaciones/previsualizar (RN-02-AC6): mismo cuerpo, validación y permiso que
 * crear. No exige Idempotency-Key (no tiene efectos; si llega, se ignora).
 */
class PrevisualizarDispensacionRequest extends StoreDispensacionRequest
{
    protected function exigeIdempotencyKey(): bool
    {
        return false;
    }
}
