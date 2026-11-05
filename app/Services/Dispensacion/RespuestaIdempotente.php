<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

/**
 * RN-09: respuesta de la creación de una dispensación tal como se envió la primera vez (status y
 * cuerpo JSON literal, byte a byte). `reproducida` = true cuando sale de dispensaciones (reintento
 * con la misma clave y el mismo cuerpo) en vez de crearse ahora.
 */
final readonly class RespuestaIdempotente
{
    public function __construct(
        public int $status,
        public string $cuerpo,
        public bool $reproducida = false,
    ) {}
}
