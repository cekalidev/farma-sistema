<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * RN-10-AC3: enmascarado de los datos personales del paciente para el rol auditor. Puro (sin BD
 * ni estado); lo aplica solo App\Http\Resources\PacienteResource.
 *
 * Decisiones (S-76):
 *  - Longitud fija de asteriscos (6 en documento, 7 en teléfono, 3 en nombres y correo): el
 *    resultado no revela cuántos caracteres tenía el valor.
 *  - Si el valor es tan corto que mostrar su cola lo revelaría entero (documento de 4 caracteres o
 *    menos, teléfono de 3 o menos), se enmascara por completo.
 *  - Correo sin `@` (mal formado): `***`, sin inicial (no se sabe qué parte es el usuario).
 *  - null → null; cadena vacía o solo espacios → null (no hay nada que mostrar).
 */
final class Enmascarador
{
    public const MASCARA_DOCUMENTO = '******';

    public const MASCARA_TELEFONO = '*******';

    public const MASCARA = '***';

    /** Caracteres visibles al final del documento. */
    public const VISIBLES_DOCUMENTO = 4;

    /** Caracteres visibles al final del teléfono. */
    public const VISIBLES_TELEFONO = 3;

    /** `1234565678` → `******5678`; 4 caracteres o menos → `******`. */
    public static function documento(?string $documento): ?string
    {
        return self::cola($documento, self::MASCARA_DOCUMENTO, self::VISIBLES_DOCUMENTO);
    }

    /** `3001234234` → `*******234`; 3 caracteres o menos → `*******`. */
    public static function telefono(?string $telefono): ?string
    {
        return self::cola($telefono, self::MASCARA_TELEFONO, self::VISIBLES_TELEFONO);
    }

    /**
     * Inicial + `***` por palabra (separadas por espacios): `Juan Pablo` → `J*** P***`,
     * `de la Cruz` → `d*** l*** C***`. Un nombre con guion es una sola palabra.
     */
    public static function nombre(?string $nombre): ?string
    {
        $palabras = self::palabras($nombre);

        if ($palabras === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn (string $palabra): string => mb_substr($palabra, 0, 1).self::MASCARA,
            $palabras,
        ));
    }

    /** Solo el año (`1985-04-12` → `1985`). Un texto que no empieza por un año → null. */
    public static function fechaNacimiento(CarbonInterface|string|null $fecha): ?string
    {
        if ($fecha instanceof CarbonInterface) {
            return $fecha->format('Y');
        }

        if ($fecha === null || preg_match('/^\s*(\d{4})/', $fecha, $coincidencia) !== 1) {
            return null;
        }

        return $coincidencia[1];
    }

    /** `juan.perez@correo.test` → `j***@***`; sin `@` → `***`; `@dominio` → `***@***`. */
    public static function correo(?string $correo): ?string
    {
        $correo = self::recortado($correo);

        if ($correo === null) {
            return null;
        }

        $arroba = mb_strpos($correo, '@');

        if ($arroba === false) {
            return self::MASCARA;
        }

        return mb_substr($correo, 0, min(1, $arroba)).self::MASCARA.'@'.self::MASCARA;
    }

    /** La dirección nunca se muestra enmascarada: siempre null. */
    public static function direccion(?string $direccion): null
    {
        return null;
    }

    private static function cola(?string $valor, string $mascara, int $visibles): ?string
    {
        $valor = self::recortado($valor);

        if ($valor === null) {
            return null;
        }

        if (mb_strlen($valor) <= $visibles) {
            return $mascara;
        }

        return $mascara.mb_substr($valor, -$visibles);
    }

    /** @return list<string> */
    private static function palabras(?string $texto): array
    {
        $texto = self::recortado($texto);

        if ($texto === null) {
            return [];
        }

        $palabras = preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY);

        return $palabras === false ? [] : $palabras;
    }

    private static function recortado(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $recortado = preg_replace('/^\s+|\s+$/u', '', $valor) ?? trim($valor);

        return $recortado === '' ? null : $recortado;
    }
}
