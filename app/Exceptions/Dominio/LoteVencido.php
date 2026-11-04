<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use App\Models\Lote;

/**
 * 422 LOTE_VENCIDO (RN-01, S-01, S-02): el cliente nombra un lote concreto cuya
 * fecha_vencimiento es <= hoy (Bogotá). Lo usan entradas (RN-01-AC8) y traslados
 * (RN-01-AC6/AC7). La dispensación FEFO NO la usa: responde STOCK_INSUFICIENTE (S-02).
 *
 * Detalles: lote_id (null si el lote aún no existe, p. ej. una entrada que lo crearía),
 * lote_codigo, producto_id y fecha_vencimiento. Nunca datos de pacientes.
 */
final class LoteVencido extends ExcepcionDominio
{
    public static function paraLote(Lote $lote): self
    {
        return new self('', [
            'lote_id' => $lote->exists ? $lote->id : null,
            'lote_codigo' => $lote->codigo,
            'producto_id' => $lote->producto_id,
            'fecha_vencimiento' => $lote->fecha_vencimiento->toDateString(),
        ]);
    }

    public function codigo(): string
    {
        return 'LOTE_VENCIDO';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'El lote está vencido y no puede moverse.';
    }
}
