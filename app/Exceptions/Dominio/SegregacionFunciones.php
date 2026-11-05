<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

/**
 * 403 SEGREGACION_FUNCIONES (RN-08-AC1, S-71): quien solicitó un traslado no puede aprobarlo ni
 * resolver sus discrepancias, aunque sea regente. Aprobar lo respalda la BD con CHECK (RN-08-AC5);
 * resolver no (cruza tablas). Detalles: solo el id del traslado y la acción, sin datos personales.
 */
final class SegregacionFunciones extends ExcepcionDominio
{
    public const ACCION_APROBAR = 'aprobar';

    public const ACCION_RESOLVER_DISCREPANCIA = 'resolver_discrepancia';

    public static function paraTraslado(int $trasladoId, string $accion): self
    {
        return new self('', ['traslado_id' => $trasladoId, 'accion' => $accion]);
    }

    public function codigo(): string
    {
        return 'SEGREGACION_FUNCIONES';
    }

    public function status(): int
    {
        return 403;
    }

    protected function mensajePorDefecto(): string
    {
        return 'Quien solicitó el traslado no puede aprobarlo ni resolver sus discrepancias.';
    }
}
