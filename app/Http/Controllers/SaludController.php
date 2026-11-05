<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sondas para el orquestador (Render, compose, Kubernetes). Fuera de /api/v1, sin auth ni sesión.
 *
 * - /health (liveness): el proceso PHP responde. No toca la BD, para que una caída de PostgreSQL
 *   no provoque reinicios en cadena del contenedor.
 * - /ready (readiness): la BD acepta consultas y no quedan migraciones pendientes. Si falla,
 *   503: el balanceador deja de enviar tráfico a esta instancia hasta que se recupere.
 *
 * Nunca se devuelve el mensaje de la excepción (podría incluir host, usuario o SQL).
 */
final class SaludController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(Migrator $migrator): JsonResponse
    {
        $comprobaciones = ['database' => 'ok', 'migrations' => 'ok'];

        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable $e) {
            Log::warning('ready: la base de datos no responde', ['excepcion' => $e::class]);

            return $this->noDisponible(['database' => 'fail', 'migrations' => 'unknown']);
        }

        if ($this->hayMigracionesPendientes($migrator)) {
            $comprobaciones['migrations'] = 'pending';

            return $this->noDisponible($comprobaciones);
        }

        return response()->json(['status' => 'ready', 'checks' => $comprobaciones]);
    }

    private function hayMigracionesPendientes(Migrator $migrator): bool
    {
        $repositorio = $migrator->getRepository();
        if (! $repositorio->repositoryExists()) {
            return true;
        }

        $archivos = array_keys($migrator->getMigrationFiles([database_path('migrations')]));

        return array_diff($archivos, $repositorio->getRan()) !== [];
    }

    /**
     * @param  array<string, string>  $comprobaciones
     */
    private function noDisponible(array $comprobaciones): JsonResponse
    {
        return response()->json(['status' => 'unavailable', 'checks' => $comprobaciones], 503);
    }
}
