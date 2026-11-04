<?php

declare(strict_types=1);

namespace App\Exceptions\Dominio;

use App\Models\Lote;

/**
 * 422 LOTE_NO_COINCIDE: una entrada nombra un lote ya registrado (mismo producto + código) con una
 * fecha de vencimiento distinta de la guardada (S-23). La fecha de un lote no se cambia desde una entrada.
 * Tiene prioridad sobre LOTE_VENCIDO: aunque la fecha enviada esté vencida, la discrepancia manda.
 */
final class LoteNoCoincide extends ExcepcionDominio
{
    public static function porFecha(Lote $lote, string $fechaEnviada): self
    {
        return new self('', [
            'lote_id' => $lote->id,
            'lote_codigo' => $lote->codigo,
            'fecha_registrada' => $lote->fecha_vencimiento->toDateString(),
            'fecha_enviada' => $fechaEnviada,
        ]);
    }

    public function codigo(): string
    {
        return 'LOTE_NO_COINCIDE';
    }

    public function status(): int
    {
        return 422;
    }

    protected function mensajePorDefecto(): string
    {
        return 'El lote ya existe con otra fecha de vencimiento.';
    }
}
