<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 422 IDEMPOTENCY_KEY_REUTILIZADA (RN-09-AC2, S-10): el usuario ya usó esa `Idempotency-Key` en una
 * dispensación creada con otro cuerpo. No se crea nada ni se mueve stock.
 */
final class IdempotencyKeyReutilizada extends ExcepcionDominio
{
    public function codigo(): string
    {
        return 'IDEMPOTENCY_KEY_REUTILIZADA';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La Idempotency-Key ya se usó con un cuerpo distinto. Use una clave nueva para otra dispensación.';
    }
}
