<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * `tap` de los canales de config/logging.php: añade RedactorDatosSensibles al canal (RN-10-AC8).
 * LogManager aplica los `tap` ANTES de añadir su ContextLogProcessor, y Monolog ejecuta los
 * procesadores del último añadido al primero: el Context de Laravel se incorpora a `extra` primero
 * y el redactor lo revisa después, junto con el contexto del registro.
 */
final class AplicarRedactor
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactorDatosSensibles);
        }
    }
}
