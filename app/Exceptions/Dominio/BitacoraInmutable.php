<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * RN-10-AC10: una fila de la bitácora `auditoria_accesos` nunca se edita ni se borra (lo lanza
 * AuditoriaAcceso en save()/update()/delete() y en el evento `updating`). El renderizador también
 * responde con esta excepción cuando es el trigger de BD el que rechaza la operación.
 *
 * Ninguna ruta lo provoca: si ocurre es un fallo de programación, así que se reporta al log (como
 * KardexInmutableException) aunque responda 409 BITACORA_INMUTABLE y no 500.
 */
final class BitacoraInmutable extends ExcepcionDominio
{
    public static function paraOperacion(string $operacion, ?int $registroId): self
    {
        return new self("La bitácora de auditoría es inmutable: {$operacion} no permitido.", [
            'operacion' => $operacion,
            'registro_id' => $registroId,
        ]);
    }

    public function codigo(): string
    {
        return 'BITACORA_INMUTABLE';
    }

    public function status(): int
    {
        return 409;
    }

    protected function mensajePorDefecto(): string
    {
        return 'La bitácora de auditoría es inmutable.';
    }
}
