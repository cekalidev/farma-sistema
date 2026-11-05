<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Models\Dispensacion;
use App\Models\Paciente;
use App\Models\User;
use App\Services\Auditoria\Bitacora;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-10-AC6: un 403 sobre una ruta que expone pacientes queda en la bitácora con resultado
 * DENEGADO. Envuelve al middleware `can:` y al Form Request (los dos convierten la denegación en
 * respuesta 403 dentro de la tubería), así que mira la respuesta. El paciente se registra si la
 * ruta ya lo resolvió (`{paciente}` o el de `{dispensacion}`); si no, paciente_id = null. Nunca
 * guarda el cuerpo (en la búsqueda lleva el documento).
 *
 * Los accesos PERMITIDOS los registra el servicio de la consulta, no este middleware.
 */
final class RegistrarAccesoDenegado
{
    public const ALIAS = 'bitacora.denegados';

    public function __construct(private readonly Bitacora $bitacora) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $usuario = $request->user();
        $accion = AccionAuditoria::deRuta($request->route()?->getName());

        if ($respuesta->getStatusCode() === Response::HTTP_FORBIDDEN && $usuario instanceof User && $accion !== null) {
            $paciente = $request->route('paciente');
            $dispensacion = $request->route('dispensacion');

            $this->bitacora->accesoDenegado(
                $usuario->id,
                $accion,
                match (true) {
                    $paciente instanceof Paciente => $paciente->id,
                    $dispensacion instanceof Dispensacion => $dispensacion->paciente_id,
                    default => null,
                },
                $dispensacion instanceof Dispensacion ? EntidadAuditada::Dispensacion : null,
                $dispensacion instanceof Dispensacion ? $dispensacion->id : null,
            );
        }

        return $respuesta;
    }
}
