<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 409 STOCK_INSUFICIENTE: el movimiento dejaría la existencia en negativo, o el stock vigente de
 * un producto en la bodega no alcanza para la dispensación FEFO (RN-02, RN-03-AC2, S-02). Se evalúa
 * sobre el saldo bloqueado y no se modifica nada. Detalles: solo ids y cantidades.
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

    /**
     * RN-07-AC10: el origen no tiene `solicitado` unidades del lote del ítem al despachar.
     * `existencia_id` es null si la bodega origen no tiene existencia de ese lote.
     */
    public static function paraItemTraslado(int $trasladoItemId, int $loteId, ?int $existenciaId, int $disponible, int $solicitado): self
    {
        return new self('', [
            'traslado_item_id' => $trasladoItemId,
            'lote_id' => $loteId,
            'existencia_id' => $existenciaId,
            'disponible' => $disponible,
            'solicitado' => $solicitado,
        ]);
    }

    /**
     * FEFO (RN-01-AC5, RN-03-AC2, S-02): `disponible` es el stock vigente (no vencido) y
     * `vencido_no_dispensable` el que existe pero no puede dispensarse (vence hoy o antes).
     */
    public static function paraAsignacion(int $solicitado, int $disponible, int $vencidoNoDispensable): self
    {
        return new self('', [
            'solicitado' => $solicitado,
            'disponible' => $disponible,
            'vencido_no_dispensable' => $vencidoNoDispensable,
        ]);
    }

    /**
     * Copia con detalles adicionales (p. ej. producto_id, bodega_id): el asignador FEFO es puro y
     * no conoce el contexto; lo añade quien lo llama.
     *
     * @param  array<string, int>  $contexto
     */
    public function conContexto(array $contexto): self
    {
        return new self($this->getMessage(), [...$contexto, ...$this->detalles], $this);
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
