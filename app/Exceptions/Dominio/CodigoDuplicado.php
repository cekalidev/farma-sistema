<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use Throwable;

/**
 * 409 cuando el INSERT choca con una restricción única de un catálogo (carrera entre dos altas
 * que pasaron la validación a la vez). Detalles: solo el nombre del campo, nunca el valor.
 */
final class CodigoDuplicado extends ExcepcionDominio
{
    public function __construct(string $campo, ?Throwable $previa = null)
    {
        parent::__construct('', ['campo' => $campo], $previa);
    }

    public function codigo(): string
    {
        return 'CODIGO_DUPLICADO';
    }

    public function status(): int
    {
        return 409;
    }

    protected function mensajePorDefecto(): string
    {
        return 'Ya existe un registro con ese valor.';
    }
}
