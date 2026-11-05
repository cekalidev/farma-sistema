<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use App\Models\Prescripcion;

/**
 * 422 PRESCRIPCION_NO_VIGENTE (RN-04-AC2, RN-04-AC3, S-05): la prescripción está ANULADA o su
 * fecha_vencimiento es anterior a hoy (Bogotá; vence hoy → vigente). Detalles: solo id, estado y
 * fecha (RN-10).
 */
final class PrescripcionNoVigente extends ExcepcionDominio
{
    public static function para(Prescripcion $prescripcion): self
    {
        return new self('', [
            'prescripcion_id' => $prescripcion->id,
            'estado' => $prescripcion->estado->value,
            'fecha_vencimiento' => $prescripcion->fecha_vencimiento->toDateString(),
        ]);
    }

    public function codigo(): string
    {
        return 'PRESCRIPCION_NO_VIGENTE';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La prescripción no está vigente (anulada o vencida).';
    }
}
