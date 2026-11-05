<?php

use App\Exceptions\Dominio\BitacoraInmutable;
use App\Exceptions\Dominio\ExcepcionDominio;
use App\Exceptions\Dominio\KardexInmutableException;
use App\Exceptions\ErroresBaseDatos;
use App\Exceptions\RenderizadorErroresApi;
use App\Http\Middleware\AsignarCorrelationId;
use App\Http\Middleware\RegistrarAccesoDenegado;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// RN-10-AC8: las trazas de excepción no incluyen argumentos de función (podrían ser datos de un
// paciente). Es el valor de producción de PHP; se fija aquí para no depender del php.ini.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API solo con Bearer: un invitado nunca se redirige (no hay ruta "login"); recibe 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);

        // RN-10: id de correlación en el Context (logs y bitácora) y en la cabecera de toda respuesta.
        $middleware->prepend(AsignarCorrelationId::class);

        // RN-10-AC6: 403 sobre rutas de paciente → bitácora DENEGADO (rutas en routes/api.php).
        $middleware->alias([RegistrarAccesoDenegado::ALIAS => RegistrarAccesoDenegado::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores de negocio esperados: no se reportan al log (evita ruido y datos de entrada).
        // Excepto las de inmutabilidad (kardex, bitácora): ninguna ruta las provoca, son fallos de código.
        $exceptions->dontReportWhen(
            fn (Throwable $e): bool => $e instanceof ExcepcionDominio
                && ! $e instanceof KardexInmutableException
                && ! $e instanceof BitacoraInmutable,
        );

        // RN-10-AC8: estos campos nunca se guardan en la sesión como "old input" (la API no usa
        // sesión; es defensa en profundidad). La validación (ValidationException) no se reporta.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token',
            'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono', 'correo', 'direccion']);

        // Errores de BD: se registran con SQLSTATE, conexión y ruta, nunca con el SQL ni los
        // bindings (RN-10: pueden ser datos de pacientes). Devuelve false → sin registro por defecto.
        $exceptions->report(fn (Throwable $e): ?bool => ErroresBaseDatos::reportar($e));

        // API pura (sin vistas): todo error se responde en JSON, también fuera de api/* (p. ej. GET /).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Formato único {"error": {"codigo", "mensaje", "detalles"}} para api/*.
        $exceptions->render(new RenderizadorErroresApi);
    })->create();
