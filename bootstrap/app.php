<?php

use App\Exceptions\Dominio\ExcepcionDominio;
use App\Exceptions\ErroresBaseDatos;
use App\Exceptions\RenderizadorErroresApi;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API solo con Bearer: un invitado nunca se redirige (no hay ruta "login"); recibe 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores de negocio esperados: no se reportan al log (evita ruido y datos de entrada).
        $exceptions->dontReport([ExcepcionDominio::class]);

        // Errores de BD: se registran con SQLSTATE, conexión y ruta, nunca con el SQL ni los
        // bindings (RN-10: pueden ser datos de pacientes). Devuelve false → sin registro por defecto.
        $exceptions->report(fn (Throwable $e): ?bool => ErroresBaseDatos::reportar($e));

        // API pura (sin vistas): todo error se responde en JSON, también fuera de api/* (p. ej. GET /).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Formato único {"error": {"codigo", "mensaje", "detalles"}} para api/*.
        $exceptions->render(new RenderizadorErroresApi);
    })->create();
