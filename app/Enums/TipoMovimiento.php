<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de movimiento de kardex (RN-06-AC1). La BD respalda valores y signo con CHECK.
 */
enum TipoMovimiento: string
{
    /** Compra / entrada de inventario (RN-01, S-17). */
    case Entrada = 'ENTRADA';
    /** Salida por dispensación (RN-02). */
    case SalidaDispensacion = 'SALIDA_DISPENSACION';
    /** Salida de la bodega origen al despachar un traslado (RN-07). */
    case SalidaTraslado = 'SALIDA_TRASLADO';
    /** Entrada en la bodega destino al recibir un traslado (RN-07). */
    case EntradaTraslado = 'ENTRADA_TRASLADO';
    /** Corrección manual con motivo (RN-06-AC6); admite ambos signos. */
    case Ajuste = 'AJUSTE';

    /** ¿La cantidad (con signo, ≠ 0) es coherente con el tipo? */
    public function admiteCantidad(int $cantidad): bool
    {
        return match ($this) {
            self::Entrada, self::EntradaTraslado => $cantidad > 0,
            self::SalidaDispensacion, self::SalidaTraslado => $cantidad < 0,
            self::Ajuste => $cantidad !== 0,
        };
    }

    /** Documento origen que exige el tipo; null = el tipo no lleva documento. */
    public function documentoRequerido(): ?DocumentoOrigen
    {
        return match ($this) {
            self::SalidaDispensacion => DocumentoOrigen::Dispensacion,
            self::SalidaTraslado, self::EntradaTraslado => DocumentoOrigen::Traslado,
            self::Entrada, self::Ajuste => null,
        };
    }

    public function exigeMotivo(): bool
    {
        return $this === self::Ajuste;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $tipo): string => $tipo->value, self::cases());
    }
}
