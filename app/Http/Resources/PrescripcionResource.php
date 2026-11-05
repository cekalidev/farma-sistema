<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Prescripción con sus ítems (cantidad prescrita y pendiente, RN-04) y el paciente anidado vía
 * PacienteResource (RN-10-AC4: enmascarado para el auditor). `observaciones` de la prescripción e
 * `indicaciones` de cada ítem son texto clínico libre (pueden contener un diagnóstico): solo los ven
 * los roles que ven al paciente completo; para el resto salen null (S-79).
 *
 * @mixin Prescripcion
 */
class PrescripcionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $textoClinico = PacienteResource::veDatosCompletos($request);

        return [
            'id' => $this->id,
            'paciente_id' => $this->paciente_id,
            'medico_id' => $this->medico_id,
            'estado' => $this->estado->value,
            'fecha_emision' => $this->fecha_emision->toDateString(),
            'fecha_vencimiento' => $this->fecha_vencimiento->toDateString(),
            'observaciones' => $textoClinico ? $this->observaciones : null,
            'paciente' => $this->whenLoaded('paciente', fn (): PacienteResource => new PacienteResource($this->paciente)),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (PrescripcionItem $item): array => [
                'id' => $item->id,
                'producto_id' => $item->producto_id,
                'producto' => $item->relationLoaded('producto') ? [
                    'id' => $item->producto->id,
                    'codigo' => $item->producto->codigo,
                    'nombre' => $item->producto->nombre,
                    'control_especial' => $item->producto->control_especial,
                ] : null,
                'cantidad_prescrita' => $item->cantidad,
                'cantidad_pendiente' => $item->pendienteCalculado,
                'indicaciones' => $textoClinico ? $item->indicaciones : null,
            ])->all()),
        ];
    }
}
