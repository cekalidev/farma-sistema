<?php

return [
    // Zona horaria de negocio: "hoy" = now(zona)->toDateString() (S-01, S-05). Independiente de
    // APP_TIMEZONE para que cambiar la zona de la app no mueva la frontera de vencimiento.
    'zona_horaria' => env('FARMA_ZONA_HORARIA', 'America/Bogota'),

    // RN-11: umbral de alerta de vencimiento en días.
    'dias_alerta_vencimiento' => (int) env('FARMA_DIAS_ALERTA_VENCIMIENTO', 90),

    // Rate limit de login (intentos por minuto); se aplican los tres a la vez.
    'login_intentos_por_minuto' => (int) env('FARMA_LOGIN_INTENTOS_POR_MINUTO', 5),                       // por correo + IP
    'login_intentos_por_correo_por_minuto' => (int) env('FARMA_LOGIN_INTENTOS_POR_CORREO_POR_MINUTO', 10), // por correo (cualquier IP)
    'login_intentos_por_ip_por_minuto' => (int) env('FARMA_LOGIN_INTENTOS_POR_IP_POR_MINUTO', 20),         // por IP (cualquier correo)

    // Límite general del grupo /api/v1 autenticado: peticiones por minuto por usuario (por IP sin usuario).
    'api_peticiones_por_minuto' => (int) env('FARMA_API_PETICIONES_POR_MINUTO', 60),

    // La semilla crea usuarios con contraseña de desarrollo conocida: en production se niega
    // salvo FARMA_PERMITIR_SEMILLA=true explícito (p. ej. un despliegue de demostración).
    'permitir_semilla_en_produccion' => (bool) env('FARMA_PERMITIR_SEMILLA', false),
];
