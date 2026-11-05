<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

/**
 * RN-09: `Idempotency-Key` del cliente (ya validada: 1–255 caracteres ASCII imprimibles) y hash
 * canónico (HashCanonico) del cuerpo validado. Alcance: el usuario que dispensa (S-11).
 */
final readonly class ClaveIdempotencia
{
    public function __construct(
        public string $clave,
        public string $hash,
    ) {}
}
