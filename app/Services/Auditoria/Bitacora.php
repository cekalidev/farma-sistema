<?php

declare(strict_types=1);

namespace App\Services\Auditoria;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\ResultadoAcceso;
use App\Models\AuditoriaAcceso;
use App\Support\Correlacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RN-10: ÚNICO escritor de `auditoria_accesos` (solo INSERT; la tabla es inmutable, AC10).
 *
 *  - consultaPermitida(): consulta exitosa de datos de paciente (AC5): una fila por paciente.
 *  - accesoDenegado(): 403 sobre una ruta de paciente (AC6).
 *  - operacion(): operación sensible (AC9). Exige una transacción abierta: el llamador la registra
 *    en la MISMA transacción que la operación, así que si esta se revierte, su fila también.
 *
 * Nunca guarda datos personales: ids, ruta (las de paciente solo llevan ids), método, IP y
 * correlation id. El contexto HTTP sale de la petición en curso; fuera de una ruta (consola,
 * servicios llamados desde tests) ruta, método e IP quedan en null.
 */
final class Bitacora
{
    /**
     * @param  list<int>  $pacienteIds
     */
    public function consultaPermitida(
        int $usuarioId,
        AccionAuditoria $accion,
        array $pacienteIds,
        ?EntidadAuditada $entidad = null,
        ?int $entidadId = null,
    ): void {
        $filas = array_map(
            fn (int $pacienteId): array => $this->fila($usuarioId, $accion, ResultadoAcceso::Permitido, $pacienteId, $entidad, $entidadId),
            array_values(array_unique($pacienteIds)),
        );

        if ($filas !== []) {
            AuditoriaAcceso::query()->insert($filas);
        }
    }

    public function accesoDenegado(
        int $usuarioId,
        AccionAuditoria $accion,
        ?int $pacienteId,
        ?EntidadAuditada $entidad = null,
        ?int $entidadId = null,
    ): void {
        AuditoriaAcceso::query()->insert(
            $this->fila($usuarioId, $accion, ResultadoAcceso::Denegado, $pacienteId, $entidad, $entidadId),
        );
    }

    /**
     * @throws \LogicException si no hay una transacción abierta (la fila debe ir con la operación)
     */
    public function operacion(
        int $usuarioId,
        AccionAuditoria $accion,
        EntidadAuditada $entidad,
        int $entidadId,
        ?int $pacienteId = null,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('La bitácora de una operación sensible se escribe dentro de su transacción.');
        }

        AuditoriaAcceso::query()->insert(
            $this->fila($usuarioId, $accion, ResultadoAcceso::Permitido, $pacienteId, $entidad, $entidadId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(
        int $usuarioId,
        AccionAuditoria $accion,
        ResultadoAcceso $resultado,
        ?int $pacienteId,
        ?EntidadAuditada $entidad,
        ?int $entidadId,
    ): array {
        $peticion = $this->peticionEnRuta();

        return [
            'usuario_id' => $usuarioId,
            'paciente_id' => $pacienteId,
            'accion' => $accion->value,
            'resultado' => $resultado->value,
            'entidad' => $entidad?->value,
            'entidad_id' => $entidad !== null ? $entidadId : null,
            // Ruta sin query string: las rutas de paciente solo llevan ids (el documento va en el cuerpo, AC7).
            'ruta' => $peticion !== null ? mb_substr('/'.ltrim($peticion->path(), '/'), 0, 255) : null,
            'metodo' => $peticion?->method(),
            'ip' => $peticion?->ip(),
            'correlation_id' => Correlacion::id(),
            'created_at' => now(),
        ];
    }

    private function peticionEnRuta(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $peticion = app('request');

        return $peticion->route() !== null ? $peticion : null;
    }
}
