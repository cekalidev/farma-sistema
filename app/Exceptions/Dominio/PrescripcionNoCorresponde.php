<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 422 PRESCRIPCION_NO_CORRESPONDE (RN-04-AC4): la prescripción no es del paciente indicado, o un
 * `prescripcion_item_id` no pertenece a la prescripción. Detalles: solo ids (RN-10).
 */
final class PrescripcionNoCorresponde extends ExcepcionDominio
{
    public static function paraPaciente(int $prescripcionId, int $pacienteId): self
    {
        return new self('La prescripción no corresponde al paciente.', [
            'prescripcion_id' => $prescripcionId,
            'paciente_id' => $pacienteId,
        ]);
    }

    /**
     * @param  list<int>  $itemIds
     */
    public static function paraItems(int $prescripcionId, array $itemIds): self
    {
        return new self('Uno o más ítems no pertenecen a la prescripción.', [
            'prescripcion_id' => $prescripcionId,
            'prescripcion_item_ids' => $itemIds,
        ]);
    }

    public function codigo(): string
    {
        return 'PRESCRIPCION_NO_CORRESPONDE';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La prescripción no corresponde a la solicitud.';
    }
}
