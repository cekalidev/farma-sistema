<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Id de correlación de la petición o proceso en curso (RN-10). Vive en el Context de Laravel, que
 * lo añade a cada registro de log (`extra.correlation_id`); lo fija el middleware
 * AsignarCorrelationId (siempre un UUID del servidor, S-80) y lo usa la bitácora. Fuera de una
 * petición (consola, colas) se genera uno.
 *
 * El id que envíe el cliente solo se anota en el Context como dato informativo del log
 * (`extra.correlation_id_cliente`); la bitácora nunca lo lee.
 */
final class Correlacion
{
    public const CLAVE = 'correlation_id';

    /** Clave del Context con el id que envió el cliente (solo log, informativo; S-80). */
    public const CLAVE_CLIENTE = 'correlation_id_cliente';

    /** Id del cliente que se anota en el log: 8 a 64 caracteres [A-Za-z0-9._-] (sin espacios ni saltos: no inyecta en logs). */
    public const PATRON = '/^[A-Za-z0-9._-]{8,64}$/D';

    public static function id(): string
    {
        $actual = Context::get(self::CLAVE);

        if (is_string($actual) && $actual !== '') {
            return $actual;
        }

        return self::establecer(self::nuevo());
    }

    public static function establecer(string $id): string
    {
        Context::add(self::CLAVE, $id);

        return $id;
    }

    public static function nuevo(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Anota en el log el id válido que envió el cliente; null (no envió uno válido) quita el de una
     * petición anterior del mismo proceso.
     */
    public static function anotarDelCliente(?string $idCliente): void
    {
        if ($idCliente === null) {
            Context::forget(self::CLAVE_CLIENTE);

            return;
        }

        Context::add(self::CLAVE_CLIENTE, $idCliente);
    }

    /** El valor del cliente si es válido; si no, null (se descarta). */
    public static function validar(mixed $valor): ?string
    {
        return is_string($valor) && preg_match(self::PATRON, $valor) === 1 ? $valor : null;
    }
}
