<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 400 IDEMPOTENCY_KEY_REQUERIDA (RN-09-AC3): POST /api/v1/dispensaciones sin cabecera
 * `Idempotency-Key`, repetida, vacía, de más de 255 caracteres o con caracteres fuera de ASCII
 * imprimible. Sin detalles: nunca se devuelve el valor recibido.
 */
final class IdempotencyKeyRequerida extends ExcepcionDominio
{
    public function codigo(): string
    {
        return 'IDEMPOTENCY_KEY_REQUERIDA';
    }

    public function status(): int
    {
        return 400;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La cabecera Idempotency-Key es obligatoria: un valor de 1 a 255 caracteres ASCII imprimibles.';
    }
}
