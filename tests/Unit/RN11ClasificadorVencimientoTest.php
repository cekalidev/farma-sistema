<?php

declare(strict_types=1);

use App\Services\Inventario\ClasificadorVencimiento;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/*
 * RN-11 — función crítica: clasificación de un lote por días restantes hasta su vencimiento.
 *
 * Spec (rn10-rn11.md, RN-11): alerta los lotes que vencen en `umbral` días o menos (90 por defecto,
 * configurable). S-01: vence hoy = vencido; "hoy" es la fecha calendario en America/Bogota.
 *
 *   dias_restantes = fecha_vencimiento − hoy (días calendario de Bogotá)
 *   dias_restantes <= 0           → VENCIDO
 *   1 <= dias_restantes <= umbral → POR_VENCER con dias_restantes
 *   dias_restantes > umbral       → null (sin alerta)
 *
 * Contrato supuesto: ClasificadorVencimiento::clasificar(CarbonInterface $fecha, CarbonInterface $hoy,
 * int $umbral). Se invoca con sintaxis de instancia (válida también si el método es estático). El
 * resultado se normaliza con rn11Normalizar (array, objeto con tipo/dias_restantes o enum) para no
 * atar el test a la forma interna; los valores esperados sí son exactos.
 *
 * Extiende el TestCase de Laravel SIN RefreshDatabase (no toca la BD): la zona de negocio sale de
 * config('farma.zona_horaria'), igual que Lote::estaVencido.
 */
uses(TestCase::class);

const RN11U_ZONA = 'America/Bogota';

/**
 * Normaliza el resultado del clasificador a ['tipo' => string, 'dias_restantes' => ?int] o null.
 *
 * @return array{tipo: mixed, dias_restantes: mixed}|null
 */
function rn11Normalizar(mixed $resultado): ?array
{
    if ($resultado === null) {
        return null;
    }

    $tipo = null;
    $dias = null;
    if (is_array($resultado)) {
        $tipo = $resultado['tipo'] ?? null;
        $dias = $resultado['dias_restantes'] ?? $resultado['diasRestantes'] ?? null;
    } elseif ($resultado instanceof BackedEnum) {
        $tipo = $resultado;
    } elseif (is_object($resultado)) {
        $tipo = $resultado->tipo ?? null;
        $dias = $resultado->dias_restantes ?? $resultado->diasRestantes ?? null;
    }

    if ($tipo instanceof BackedEnum) {
        $tipo = $tipo->value;
    }

    return ['tipo' => $tipo, 'dias_restantes' => $dias];
}

function rn11Clasificar(string $fechaVencimiento, CarbonImmutable $hoy, int $umbral = 90): ?array
{
    $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $fechaVencimiento, RN11U_ZONA);

    return rn11Normalizar((new ClasificadorVencimiento)->clasificar($fecha, $hoy, $umbral));
}

/** Fecha Y-m-d a N días de `base` (Bogotá). */
function rn11UDia(string $base, int $dias): string
{
    return CarbonImmutable::parse($base, RN11U_ZONA)->addDays($dias)->toDateString();
}

describe('RN-11 clasificación por días restantes (función pura)', function () {
    it('RN-11-AC1 RN-11-AC2: frontera de días con umbral 90 (S-01: 0 días = VENCIDO)', function (int $dias, ?string $tipo, ?int $diasRestantes) {
        $hoy = CarbonImmutable::parse('2026-11-05 10:00:00', RN11U_ZONA);

        $resultado = rn11Clasificar(rn11UDia('2026-11-05', $dias), $hoy);

        if ($tipo === null) {
            expect($resultado)->toBeNull();
        } elseif ($tipo === 'VENCIDO') {
            expect($resultado)->not->toBeNull()
                ->and($resultado['tipo'])->toBe('VENCIDO');
        } else {
            expect($resultado)->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => $diasRestantes]);
        }
    })->with([
        'venció hace 400 días' => [-400, 'VENCIDO', null],
        'venció ayer (−1)' => [-1, 'VENCIDO', null],
        'vence hoy (0) → vencido, S-01' => [0, 'VENCIDO', null],
        'vence mañana (+1)' => [1, 'POR_VENCER', 1],
        '+2' => [2, 'POR_VENCER', 2],
        '+89' => [89, 'POR_VENCER', 89],
        '+90 (umbral, incluido)' => [90, 'POR_VENCER', 90],
        '+91 (fuera)' => [91, null, null],
        '+365' => [365, null, null],
    ]);

    it('RN-11-AC1: la hora de "hoy" no cuenta; a las 23:59:59 de Bogotá un lote que vence mañana tiene 1 día (no 0)', function (string $fecha, ?string $tipo, ?int $diasRestantes) {
        $hoy = CarbonImmutable::parse('2026-11-05 23:59:59', RN11U_ZONA);

        $resultado = rn11Clasificar($fecha, $hoy);

        if ($tipo === null) {
            expect($resultado)->toBeNull();
        } elseif ($tipo === 'VENCIDO') {
            expect($resultado['tipo'] ?? null)->toBe('VENCIDO');
        } else {
            expect($resultado)->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => $diasRestantes]);
        }
    })->with([
        'vence hoy 2026-11-05 → VENCIDO' => ['2026-11-05', 'VENCIDO', null],
        'vence 2026-11-06 → 1' => ['2026-11-06', 'POR_VENCER', 1],
        'vence 2027-02-03 (hoy+90) → 90' => ['2027-02-03', 'POR_VENCER', 90],
        'vence 2027-02-04 (hoy+91) → sin alerta' => ['2027-02-04', null, null],
    ]);

    it('RN-11-AC2 S-01: a las 00:00:00 de Bogotá el día cambia: lo que vencía "mañana" ya está VENCIDO y +91 de ayer pasa a 90', function (string $fecha, ?string $tipo, ?int $diasRestantes) {
        $hoy = CarbonImmutable::parse('2026-11-06 00:00:00', RN11U_ZONA);

        $resultado = rn11Clasificar($fecha, $hoy);

        if ($tipo === 'VENCIDO') {
            expect($resultado['tipo'] ?? null)->toBe('VENCIDO');
        } else {
            expect($resultado)->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => $diasRestantes]);
        }
    })->with([
        'vence 2026-11-06 → VENCIDO' => ['2026-11-06', 'VENCIDO', null],
        'vence 2026-11-07 → 1' => ['2026-11-07', 'POR_VENCER', 1],
        'vence 2027-02-03 → 89' => ['2027-02-03', 'POR_VENCER', 89],
        'vence 2027-02-04 → 90' => ['2027-02-04', 'POR_VENCER', 90],
    ]);

    it('RN-11-AC2 S-01: "hoy" expresado en UTC se interpreta como día de Bogotá (04:59:59 UTC sigue siendo el día anterior en Bogotá)', function () {
        // 2026-11-06 04:59:59 UTC = 2026-11-05 23:59:59 en Bogotá (UTC−5).
        $hoyUtc = CarbonImmutable::parse('2026-11-06 04:59:59', 'UTC');
        // 2026-11-06 05:00:00 UTC = 2026-11-06 00:00:00 en Bogotá.
        $hoyUtcMedianoche = CarbonImmutable::parse('2026-11-06 05:00:00', 'UTC');

        expect(rn11Clasificar('2026-11-06', $hoyUtc))->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => 1])
            ->and(rn11Clasificar('2026-11-05', $hoyUtc)['tipo'] ?? null)->toBe('VENCIDO')
            ->and(rn11Clasificar('2026-11-06', $hoyUtcMedianoche)['tipo'] ?? null)->toBe('VENCIDO')
            ->and(rn11Clasificar('2026-11-07', $hoyUtcMedianoche))->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => 1]);
    });

    it('RN-11-AC1: días calendario exactos al cruzar fin de año y un 29 de febrero', function (string $hoy, string $fecha, ?int $diasRestantes) {
        $resultado = rn11Clasificar($fecha, CarbonImmutable::parse($hoy.' 08:00:00', RN11U_ZONA));

        if ($diasRestantes === null) {
            expect($resultado)->toBeNull();
        } else {
            expect($resultado)->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => $diasRestantes]);
        }
    })->with([
        'fin de año: 2026-12-31 → 2027-03-31 = 90' => ['2026-12-31', '2027-03-31', 90],
        'fin de año: 2026-12-31 → 2027-04-01 = 91 (sin alerta)' => ['2026-12-31', '2027-04-01', null],
        'bisiesto: 2028-02-28 → 2028-03-01 = 2' => ['2028-02-28', '2028-03-01', 2],
        'bisiesto: 2028-01-01 → 2028-03-31 = 90' => ['2028-01-01', '2028-03-31', 90],
        'bisiesto: 2028-01-01 → 2028-04-01 = 91 (sin alerta)' => ['2028-01-01', '2028-04-01', null],
    ]);

    it('RN-11-AC1 umbral configurable: el límite superior es el umbral recibido, no 90 fijo', function (int $umbral, int $dias, ?string $tipo, ?int $diasRestantes) {
        $hoy = CarbonImmutable::parse('2026-11-05 10:00:00', RN11U_ZONA);

        $resultado = rn11Clasificar(rn11UDia('2026-11-05', $dias), $hoy, $umbral);

        if ($tipo === null) {
            expect($resultado)->toBeNull();
        } elseif ($tipo === 'VENCIDO') {
            expect($resultado['tipo'] ?? null)->toBe('VENCIDO');
        } else {
            expect($resultado)->toBe(['tipo' => 'POR_VENCER', 'dias_restantes' => $diasRestantes]);
        }
    })->with([
        'umbral 30, +29' => [30, 29, 'POR_VENCER', 29],
        'umbral 30, +30 (incluido)' => [30, 30, 'POR_VENCER', 30],
        'umbral 30, +31' => [30, 31, null, null],
        'umbral 30, +45' => [30, 45, null, null],
        'umbral 30, +90 (ya no alerta)' => [30, 90, null, null],
        'umbral 30, hoy sigue VENCIDO' => [30, 0, 'VENCIDO', null],
        'umbral 120, +91 (ahora sí alerta)' => [120, 91, 'POR_VENCER', 91],
        'umbral 120, +120' => [120, 120, 'POR_VENCER', 120],
        'umbral 120, +121' => [120, 121, null, null],
        'umbral 1, +1' => [1, 1, 'POR_VENCER', 1],
        'umbral 1, +2' => [1, 2, null, null],
        'umbral 0, +1 (solo vencidos)' => [0, 1, null, null],
        'umbral 0, hoy → VENCIDO' => [0, 0, 'VENCIDO', null],
        'umbral 0, ayer → VENCIDO' => [0, -1, 'VENCIDO', null],
    ]);
});
