<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use App\Models\Dispensacion;

/**
 * 409 TRANSICION_INVALIDA: la acción no es válida desde el estado actual del recurso (RN-05-AC7,
 * RN-05-AC10: autorizar o rechazar una dispensación que ya no está PENDIENTE_AUTORIZACION). Se
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
