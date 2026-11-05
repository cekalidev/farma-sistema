<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 422 CANTIDAD_RECIBIDA_EXCEDE (RN-07-AC7): se declara recibida más cantidad de la despachada en un
 * ítem de traslado. No se modifica nada. Detalles: ids y cantidades.
 */
final class CantidadRecibidaExcede extends ExcepcionDominio
{
    public static function paraItem(int $trasladoItemId, int $despachada, int $recibida): self
    {
        return new self('', [
            'traslado_item_id' => $trasladoItemId,
            'despachada' => $despachada,
            'recibida' => $recibida,
        ]);
    }

    public function codigo(): string
    {
        return 'CANTIDAD_RECIBIDA_EXCEDE';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La cantidad recibida no puede superar la despachada.';
    }
}
