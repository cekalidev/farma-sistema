<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Correlacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-10 logs estructurados (S-80): el id de correlación de la petición lo genera SIEMPRE el
 * servidor (UUID). Queda en el Context (cada log lo lleva en `extra.correlation_id`, y la bitácora
 * lo guarda) y se devuelve en las cabeceras `X-Correlation-Id` y `X-Request-Id`, también en las
 * respuestas de error.
 *
 * El id que envíe el cliente (`X-Correlation-Id` o, si falta, `X-Request-Id`) no es de fiar: si es
 * válido (Correlacion::PATRON) solo se añade al log como dato informativo
 * (`extra.correlation_id_cliente`), para cruzarlo con los registros del cliente; nunca llega a la
 * bitácora ni a la respuesta. Uno inválido se descarta.
 */
final class AsignarCorrelationId
{
    public const CABECERA = 'X-Correlation-Id';

    public const CABECERA_ALTERNATIVA = 'X-Request-Id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = Correlacion::establecer(Correlacion::nuevo());

        Correlacion::anotarDelCliente(
            Correlacion::validar($request->headers->get(self::CABECERA))
                ?? Correlacion::validar($request->headers->get(self::CABECERA_ALTERNATIVA)),
        );

        $respuesta = $next($request);
        $respuesta->headers->set(self::CABECERA, $id);
        $respuesta->headers->set(self::CABECERA_ALTERNATIVA, $id);

        return $respuesta;
    }
}
