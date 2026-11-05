<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en toda respuesta (también errores y sondas).
 *
 * HSTS solo si la petición llegó por https: detrás de Render el TLS termina en su proxy y
 * isSecure() se fía de X-Forwarded-Proto gracias a TrustProxies. En http (local, compose) no se
 * envía, para no fijar en el navegador una política que el servidor no puede cumplir.
 */
final class CabecerasSeguridad
{
    public const HSTS = 'max-age=31536000; includeSubDomains';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('X-Frame-Options', 'DENY');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');

        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $respuesta;
    }
}
