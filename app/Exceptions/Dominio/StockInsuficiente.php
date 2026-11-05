<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 409 STOCK_INSUFICIENTE: el movimiento dejaría la existencia en negativo. Se evalúa sobre el saldo
 * bloqueado y no se modifica nada. Detalles: solo ids y cantidades.
 */
final class StockInsuficiente extends ExcepcionDominio
{
    public static function paraExistencia(int $existenciaId, int $disponible, int $solicitado): self
    {
        return new self('', [
            'existencia_id' => $existenciaId,
            'disponible' => $disponible,
            'solicitado' => $solicitado,
        ]);
    }

    public function codigo(): string
    {
        return 'STOCK_INSUFICIENTE';
    }

    public function status(): int
    {
        return 409;
    }

    protected function mensajePorDefecto(): string
    {
        return 'No hay stock suficiente para la operación.';
    }
}
