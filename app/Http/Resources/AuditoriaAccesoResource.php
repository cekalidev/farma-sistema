<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditoriaAcceso;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de la bitácora (RN-10-AC10). Solo ids del paciente, nunca sus datos: el admin también la
 * consulta y no ve datos clínicos (S-13). Del usuario, id, nombre y rol (personal, no paciente).
 *
 * @mixin AuditoriaAcceso
 */
class AuditoriaAccesoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usuario_id' => $this->usuario_id,
            'usuario' => $this->relationLoaded('usuario') ? [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->name,
                'rol' => $this->usuario->rol->value,
            ] : null,
            'paciente_id' => $this->paciente_id,
            'accion' => $this->accion->value,
            'resultado' => $this->resultado->value,
            'entidad' => $this->entidad?->value,
            'entidad_id' => $this->entidad_id,
            'ruta' => $this->ruta,
            'metodo' => $this->metodo,
            'ip' => $this->ip,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
