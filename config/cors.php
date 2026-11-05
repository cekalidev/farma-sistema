<?php

use Illuminate\Support\Env;

/*
| CORS de la API. El frontend en producción se sirve desde su propio origen (o desde el mismo
| origen vía el proxy /api/ de nginx, que no necesita CORS). Orígenes permitidos desde
| CORS_ALLOWED_ORIGINS, lista separada por comas; vacía por defecto = ningún origen cruzado.
| Sin credenciales: la API usa tokens Bearer en la cabecera Authorization, nunca cookies.
*/

$origenes = Env::get('CORS_ALLOWED_ORIGINS', '');

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map(trim(...), explode(',', is_string($origenes) ? $origenes : '')),
        fn (string $origen): bool => $origen !== '',
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Correlation-Id', 'X-Request-Id'],

    'exposed_headers' => ['X-Correlation-Id', 'X-Request-Id', 'Idempotent-Replayed', 'Retry-After',
        'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 600,

    'supports_credentials' => false,
];
