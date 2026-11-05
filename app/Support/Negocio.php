<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/**
 * Único punto de acceso a la zona horaria de negocio (S-01, S-05): "hoy" se calcula en
 * `farma.zona_horaria`, independiente de APP_TIMEZONE.
 */
final class Negocio
{
    public static function zonaHoraria(): string
    {
        return Config::string('farma.zona_horaria');
    }

    /** Inicio del día de hoy en la zona de negocio. */
    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zonaHoraria())->startOfDay();
    }

    /**
     * Instante en que empieza el día `Y-m-d` (más `$dias`) en la zona de negocio, expresado en la
     * zona de la aplicación, que es en la que Eloquent guarda los timestamps (columnas sin zona).
     * Sirve para filtrar `created_at` por días de negocio.
     */
    public static function inicioDelDia(string $fecha, int $dias = 0): CarbonImmutable
    {
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', $fecha, self::zonaHoraria());
        if (! $inicio instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('Fecha con formato distinto de Y-m-d.');
        }

        return $inicio->addDays($dias)->setTimezone(Config::string('app.timezone'));
    }
}
