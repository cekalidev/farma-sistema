<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use RuntimeException;
use Throwable;

/**
 * Base de los errores de negocio. El handler (bootstrap/app.php) la convierte en
 * {"error": {"codigo", "mensaje", "detalles"}} con el status que declara.
 *
 * Los detalles NUNCA deben incluir datos personales del paciente (RN-10): solo ids
 * y cantidades.
 */
abstract class ExcepcionDominio extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $detalles
     */
    public function __construct(
        string $mensaje = '',
        protected array $detalles = [],
        ?Throwable $previa = null,
    ) {
        parent::__construct($mensaje !== '' ? $mensaje : $this->mensajePorDefecto(), 0, $previa);
    }

    /** Código estable en MAYÚSCULAS que consume el frontend. */
    abstract public function codigo(): string;

    /** Status HTTP de la respuesta. */
    abstract public function status(): int;

    /**
     * @return array<string, mixed>
     */
    public function detalles(): array
    {
        return $this->detalles;
    }

    protected function mensajePorDefecto(): string
    {
        return 'No se pudo completar la operación.';
    }
}
