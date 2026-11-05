<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Dominio\ExcepcionDominio;
use App\Exceptions\Dominio\KardexInmutableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Convierte cualquier excepción de una ruta api/* en
 * {"error": {"codigo", "mensaje", "detalles"}}. Nunca devuelve trazas ni SQL.
 */
final class RenderizadorErroresApi
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // HttpResponseException ya trae su respuesta: la deja pasar.
        if (! $request->is('api/*') || $e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ExcepcionDominio => $this->dominio($e),
            $e instanceof AuthenticationException => $this->respuesta('NO_AUTENTICADO', 'No autenticado.', 401),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->respuesta('NO_AUTORIZADO', 'No tiene permiso para realizar esta acción.', 403),
            // S-39: el trigger de inmutabilidad del kardex (23001 de movimientos_kardex_inmutable())
            // se responde igual que KardexInmutableException; sin SQL ni valores (solo el mensaje fijo).
            ErroresBaseDatos::esKardexInmutable($e) => $this->dominio(new KardexInmutableException),
            $e instanceof ValidationException => $this->respuesta('VALIDACION', 'Los datos enviados no son válidos.', 422, ['campos' => $e->errors()]),
            // Red de seguridad (los ids de ruta ya se filtran con Route::pattern → 404). Si un valor
            // del cliente llega a la BD sin poder convertirse (22P02, o 22003 con un binding que
            // vino del cliente), el problema es el dato enviado: 422 y no 404 ni 500. Cualquier
            // otro error de BD cae en el 500 genérico. Sin detalles: nunca el SQL ni el valor.
            ErroresBaseDatos::esDatoInvalidoDelCliente($e, $request) => $this->respuesta('DATO_INVALIDO', 'Uno de los valores enviados no tiene un formato válido.', 422),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => $this->respuesta('NO_ENCONTRADO', 'Recurso no encontrado.', 404),
            $e instanceof MethodNotAllowedHttpException => $this->respuesta('METODO_NO_PERMITIDO', 'Método no permitido.', 405, [], $e->getHeaders()),
            $e instanceof ThrottleRequestsException,
            $e instanceof TooManyRequestsHttpException => $this->respuesta('DEMASIADAS_SOLICITUDES', 'Demasiados intentos. Intente más tarde.', 429, [], $e->getHeaders()),
            $e instanceof HttpExceptionInterface => $this->respuesta('ERROR_HTTP', 'La solicitud no pudo procesarse.', $e->getStatusCode(), [], $e->getHeaders()),
            default => $this->respuesta('ERROR_INTERNO', 'Error interno del servidor.', 500),
        };
    }

    private function dominio(ExcepcionDominio $e): JsonResponse
    {
        return $this->respuesta($e->codigo(), $e->getMessage(), $e->status(), $e->detalles());
    }

    /**
     * @param  array<string, mixed>  $detalles
     * @param  array<string, mixed>  $headers
     */
    private function respuesta(string $codigo, string $mensaje, int $status, array $detalles = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'codigo' => $codigo,
                'mensaje' => $mensaje,
                'detalles' => (object) $detalles,
            ],
        ], $status, $headers);
    }
}
