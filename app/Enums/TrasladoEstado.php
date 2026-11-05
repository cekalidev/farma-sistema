<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados del traslado (RN-07). ÚNICA fuente de las transiciones: `puede()` decide si una acción es
 * válida desde el estado y `tras()` da el estado resultante. Los servicios lo consultan sobre la
 * fila bloqueada; la BD respalda estados y columnas con CHECK.
 *
 *   —                              crear        → BORRADOR
 *   BORRADOR                       solicitar    → SOLICITADO        (solo el creador, S-08)
 *   SOLICITADO                     aprobar      → APROBADO          (regente; RN-08 ≠ solicitante)
 *   APROBADO                       despachar    → EN_TRANSITO       (sale stock del origen)
 *   EN_TRANSITO                    recibir      → RECIBIDO | RECIBIDO_PARCIAL
 *   BORRADOR, SOLICITADO, APROBADO anular       → ANULADO           (S-09: nunca tras despachar)
 *   BORRADOR                       editar_items → BORRADOR          (RN-07-AC9)
 */
enum TrasladoEstado: string
{
    case Borrador = 'BORRADOR';
    case Solicitado = 'SOLICITADO';
    case Aprobado = 'APROBADO';
    case EnTransito = 'EN_TRANSITO';
    case Recibido = 'RECIBIDO';
    case RecibidoParcial = 'RECIBIDO_PARCIAL';
    case Anulado = 'ANULADO';

    /** Estado con el que nace un traslado (acción `crear`, sin estado previo). */
    public static function inicial(): self
    {
        return self::Borrador;
    }

    /** ¿Es válida la acción desde este estado? `crear` no parte de ningún estado: siempre false. */
    public function puede(AccionTraslado $accion): bool
    {
        return in_array($accion, $this->accionesPermitidas(), true);
    }

    /**
     * @return list<AccionTraslado>
     */
    public function accionesPermitidas(): array
    {
        return match ($this) {
            self::Borrador => [AccionTraslado::Solicitar, AccionTraslado::Anular, AccionTraslado::EditarItems],
            self::Solicitado => [AccionTraslado::Aprobar, AccionTraslado::Anular],
            self::Aprobado => [AccionTraslado::Despachar, AccionTraslado::Anular],
            self::EnTransito => [AccionTraslado::Recibir],
            self::Recibido, self::RecibidoParcial, self::Anulado => [],
        };
    }

    /**
     * Estado al que lleva la acción. `recepcionCompleta` solo cuenta para `recibir` (todo lo
     * despachado llegó → RECIBIDO; si no → RECIBIDO_PARCIAL).
     *
     * @throws \LogicException si la acción no es válida desde este estado (el llamador debe
     *                         comprobar puede() y responder TRANSICION_INVALIDA antes).
     */
    public function tras(AccionTraslado $accion, bool $recepcionCompleta = true): self
    {
        if (! $this->puede($accion)) {
            throw new \LogicException("La acción {$accion->value} no es válida desde {$this->value}.");
        }

        return match ($accion) {
            AccionTraslado::Solicitar => self::Solicitado,
            AccionTraslado::Aprobar => self::Aprobado,
            AccionTraslado::Despachar => self::EnTransito,
            AccionTraslado::Recibir => $recepcionCompleta ? self::Recibido : self::RecibidoParcial,
            AccionTraslado::Anular => self::Anulado,
            AccionTraslado::EditarItems, AccionTraslado::Crear => $this,
        };
    }

    public function esFinal(): bool
    {
        return $this->accionesPermitidas() === [];
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(static fn (self $estado): string => $estado->value, self::cases());
    }
}
