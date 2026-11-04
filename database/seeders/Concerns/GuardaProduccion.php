<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Los usuarios semilla tienen una contraseña de desarrollo conocida: en production la semilla
 * aborta salvo FARMA_PERMITIR_SEMILLA=true explícito. La usan DatabaseSeeder y todo seeder que
 * cree credenciales, para que `db:seed --class=…` no salte la guarda.
 */
trait GuardaProduccion
{
    protected function abortarEnProduccionSinPermiso(): void
    {
        if (app()->environment('production') && ! config('farma.permitir_semilla_en_produccion')) {
            throw new RuntimeException(
                'Semilla abortada: APP_ENV=production y los usuarios de prueba usan una contraseña de desarrollo conocida. '
                .'Si es un despliegue de demostración, defina FARMA_PERMITIR_SEMILLA=true de forma explícita.'
            );
        }
    }
}
