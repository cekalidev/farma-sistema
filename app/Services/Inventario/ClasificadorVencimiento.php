<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use App\Enums\TipoAlertaVencimiento;
use App\Support\Negocio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * RN-11 (función crítica, pura): clasifica un lote por días restantes hasta su vencimiento.
 *
 * - `dias_restantes` = fecha_vencimiento − hoy en días calendario (vence mañana = 1).
 * - VENCIDO si dias_restantes <= 0 (S-01: vence hoy = vencido); 0 = vence hoy, negativo = días
 *   que lleva vencido.
 * - POR_VENCER si 1 <= dias_restantes <= umbral.
 * - null (sin alerta) si dias_restantes > umbral.
 *
 * "Hoy" se interpreta igual que Lote::estaVencido: la fecha calendario de `$hoy` en la zona de
 * negocio (America/Bogota). La fecha de vencimiento se toma como fecha calendario (Y-m-d), sin hora.
 */
final class ClasificadorVencimiento
{
    /**
     * @return array{tipo: TipoAlertaVencimiento, dias_restantes: int}|null
     */
    public static function clasificar(CarbonInterface|string $fechaVencimiento, CarbonInterface $hoy, int $umbral): ?array
    {
        if ($umbral < 0) {
            throw new \InvalidArgumentException('El umbral de alerta de vencimiento no puede ser negativo.');
        }

        $dias = self::diasRestantes($fechaVencimiento, $hoy);

        if ($dias <= 0) {
            return ['tipo' => TipoAlertaVencimiento::Vencido, 'dias_restantes' => $dias];
        }

        if ($dias <= $umbral) {
            return ['tipo' => TipoAlertaVencimiento::PorVencer, 'dias_restantes' => $dias];
        }

        return null;
    }

    /** Días calendario de hoy (zona de negocio) a la fecha de vencimiento; negativo si ya pasó. */
    public static function diasRestantes(CarbonInterface|string $fechaVencimiento, CarbonInterface $hoy): int
    {
        $vence = self::fechaUtc(is_string($fechaVencimiento) ? $fechaVencimiento : $fechaVencimiento->toDateString());
        $hoyNegocio = self::fechaUtc($hoy->copy()->setTimezone(Negocio::zonaHoraria())->toDateString());

        // Ambas a medianoche UTC: la resta es un múltiplo exacto de 86 400 s (sin horario de verano).
        return intdiv($vence->getTimestamp() - $hoyNegocio->getTimestamp(), 86_400);
    }

    private static function fechaUtc(string $fecha): CarbonImmutable
    {
        $resultado = CarbonImmutable::createFromFormat('!Y-m-d', $fecha, 'UTC');
        if (! $resultado instanceof CarbonImmutable || $resultado->toDateString() !== $fecha) {
            throw new \InvalidArgumentException('Fecha de vencimiento con formato distinto de Y-m-d.');
        }

        return $resultado;
    }
}
