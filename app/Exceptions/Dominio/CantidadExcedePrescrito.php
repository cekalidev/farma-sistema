<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 422 CANTIDAD_EXCEDE_PRESCRITO (RN-04-AC5, AC6, AC8): la cantidad pedida de un ítem supera su
 * pendiente (prescrito − lo dispensado en CONFIRMADA y PENDIENTE_AUTORIZACION). Informa el primer
 * ítem que excede, en orden de la petición. Detalles: solo ids y cantidades.
 */
final class CantidadExcedePrescrito extends ExcepcionDominio
{
    public static function paraItem(int $prescripcionItemId, int $pendiente, int $solicitado): self
    {
        return new self('', [
            'prescripcion_item_id' => $prescripcionItemId,
            'pendiente' => $pendiente,
            'solicitado' => $solicitado,
        ]);
    }

    public function codigo(): string
    {
        return 'CANTIDAD_EXCEDE_PRESCRITO';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La cantidad solicitada supera lo pendiente de la prescripción.';
    }
}
