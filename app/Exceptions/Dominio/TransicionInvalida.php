<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use App\Enums\AccionTraslado;
use App\Models\Discrepancia;
use App\Models\Dispensacion;
use App\Models\Traslado;

/**
 * 409 TRANSICION_INVALIDA: la acción no es válida desde el estado actual del recurso (RN-05-AC7,
 * RN-05-AC10: autorizar o rechazar una dispensación que ya no está PENDIENTE_AUTORIZACION; RN-07-AC3:
 * acción de traslado no válida según TrasladoEstado::puede(); RN-07-AC12: discrepancia ya resuelta). Se
 * evalúa con la fila bloqueada; no se modifica nada. Detalles: solo ids y estados.
 */
final class TransicionInvalida extends ExcepcionDominio
{
    public static function paraDispensacion(Dispensacion $dispensacion, string $accion): self
    {
        return new self('', [
            'dispensacion_id' => $dispensacion->id,
            'estado' => $dispensacion->estado->value,
            'accion' => $accion,
        ]);
    }

    public static function paraTraslado(Traslado $traslado, AccionTraslado $accion): self
    {
        return new self('', [
            'traslado_id' => $traslado->id,
            'estado' => $traslado->estado->value,
            'accion' => $accion->value,
        ]);
    }

    public static function paraDiscrepancia(Discrepancia $discrepancia): self
    {
        return new self('', [
            'discrepancia_id' => $discrepancia->id,
            'estado' => $discrepancia->estado->value,
            'accion' => 'resolver',
        ]);
    }

    public function codigo(): string
    {
        return 'TRANSICION_INVALIDA';
    }

    public function status(): int
    {
        return 409;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La acción no es válida en el estado actual.';
    }
}
