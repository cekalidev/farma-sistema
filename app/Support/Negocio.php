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
}
