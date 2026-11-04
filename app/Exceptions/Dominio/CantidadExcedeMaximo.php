<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 422 CANTIDAD_EXCEDE_MAXIMO: la entrada dejaría la existencia por encima del máximo que admite
 * la columna `existencias.cantidad` (integer de PostgreSQL). Se evalúa sobre el saldo ya
 * bloqueado y no se modifica nada. Detalles: solo cantidades.
 */
final class CantidadExcedeMaximo extends ExcepcionDominio
{
    public static function paraSaldo(int $saldoActual, int $cantidad, int $maximo): self
    {
        return new self('', [
            'saldo_actual' => $saldoActual,
            'cantidad' => $cantidad,
            'maximo' => $maximo,
        ]);
    }

    public function codigo(): string
    {
        return 'CANTIDAD_EXCEDE_MAXIMO';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La cantidad supera el saldo máximo admitido para la existencia.';
    }
}
