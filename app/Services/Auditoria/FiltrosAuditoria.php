<?php

declare(strict_types=1);

namespace App\Services\Auditoria;

use App\Enums\AccionAuditoria;
use App\Enums\ResultadoAcceso;
use Carbon\CarbonImmutable;

/**
 * Filtros combinables de GET /api/v1/auditoria (RN-10-AC10); null = sin filtrar.
 * `desde` (inclusive) y `hastaExclusivo` ya son instantes en la zona de la aplicación.
 */
final readonly class FiltrosAuditoria
{
    public function __construct(
        public ?int $usuarioId = null,
        public ?int $pacienteId = null,
        public ?ResultadoAcceso $resultado = null,
        public ?AccionAuditoria $accion = null,
        public ?CarbonImmutable $desde = null,
        public ?CarbonImmutable $hastaExclusivo = null,
        public int $porPagina = 25,
    ) {}
}
