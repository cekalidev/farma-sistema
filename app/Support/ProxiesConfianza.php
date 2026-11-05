<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Valor de TRUSTED_PROXIES para TrustProxies: '*' (por defecto: se confía solo en el proxy
 * inmediato, REMOTE_ADDR, p. ej. el de Render) o una lista de IP/CIDR separada por comas.
 */
final class ProxiesConfianza
{
    /**
     * @return '*'|list<string>
     */
    public static function desde(mixed $valor): string|array
    {
        if (! is_string($valor) || trim($valor) === '' || trim($valor) === '*') {
            return '*';
        }

        $lista = array_values(array_filter(
            array_map(trim(...), explode(',', $valor)),
            fn (string $proxy): bool => $proxy !== '',
        ));

        return $lista === [] ? '*' : $lista;
    }
}
