<?php

declare(strict_types=1);

use App\Models\Lote;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
 * RN-01-AC4 / S-01: un lote que vence hoy cuenta como vencido (fecha_vencimiento <= hoy) y
 * "hoy" es la fecha calendario en America/Bogota.
 *
 * Lote::estaVencido no toca la BD (el modelo se instancia sin guardar), pero el cast `date` y la
 * zona de negocio (config farma.zona_horaria) necesitan la aplicación arrancada: por eso este
 * archivo extiende el TestCase de Laravel SIN RefreshDatabase.
 */
uses(TestCase::class);

const RN01_ZONA = 'America/Bogota';

/** Lote en memoria (sin persistir) con la fecha de vencimiento dada (Y-m-d). */
function loteEnMemoria(string $fechaVencimiento): Lote
{
    return new Lote([
        'producto_id' => 1,
        'codigo' => 'L-UNIT-0001',
        'fecha_vencimiento' => $fechaVencimiento,
    ]);
}

describe('RN-01-AC4 Lote::estaVencido (S-01: vence hoy = vencido, hoy en Bogotá)', function () {
    it('RN-01-AC4: un lote que vence hoy está vencido y uno que vence mañana no', function () {
        $hoy = Carbon::parse('2026-11-04 10:00:00', RN01_ZONA);

        expect(loteEnMemoria('2026-11-04')->estaVencido($hoy))->toBeTrue()
            ->and(loteEnMemoria('2026-11-05')->estaVencido($hoy))->toBeFalse();
    });

    it('RN-01-AC4 frontera de días: vencido si fecha_vencimiento <= hoy', function (string $fechaVencimiento, bool $vencido) {
        $hoy = Carbon::parse('2026-11-04 12:00:00', RN01_ZONA);

        expect(loteEnMemoria($fechaVencimiento)->estaVencido($hoy))->toBe($vencido);
    })->with([
        'venció hace un año' => ['2025-11-04', true],
        'venció ayer' => ['2026-11-03', true],
        'vence hoy' => ['2026-11-04', true],
        'vence mañana' => ['2026-11-05', false],
        'vence en un año' => ['2027-11-04', false],
        'fin de mes anterior (cruce de mes)' => ['2026-10-31', true],
    ]);

    it('RN-01-AC4 frontera de hora: el resultado no depende de la hora del día en Bogotá', function (string $hora) {
        $hoy = Carbon::parse("2026-11-04 {$hora}", RN01_ZONA);

        expect(loteEnMemoria('2026-11-03')->estaVencido($hoy))->toBeTrue()
            ->and(loteEnMemoria('2026-11-04')->estaVencido($hoy))->toBeTrue()
            ->and(loteEnMemoria('2026-11-05')->estaVencido($hoy))->toBeFalse();
    })->with([
        'medianoche exacta' => ['00:00:00'],
        'un segundo después de medianoche' => ['00:00:01'],
        'mediodía' => ['12:00:00'],
        'último segundo del día' => ['23:59:59'],
    ]);

    it('RN-01-AC4 zona horaria: 23:30 en Bogotá (04:30 UTC del día siguiente) sigue siendo el día de Bogotá', function () {
        // 2026-11-05 04:30 UTC = 2026-11-04 23:30 America/Bogota (UTC-5, sin horario de verano).
        $hoyUtc = Carbon::parse('2026-11-05 04:30:00', 'UTC');

        // En Bogotá "hoy" es 2026-11-04: el lote que vence el 05 vence MAÑANA → no vencido.
        expect(loteEnMemoria('2026-11-05')->estaVencido($hoyUtc))->toBeFalse()
            // El que vence el 04 vence hoy → vencido (S-01).
            ->and(loteEnMemoria('2026-11-04')->estaVencido($hoyUtc))->toBeTrue()
            ->and(loteEnMemoria('2026-11-06')->estaVencido($hoyUtc))->toBeFalse();
    });

    it('RN-01-AC4 zona horaria: el mismo instante expresado en cualquier zona da el mismo resultado', function (string $instante, string $zona, string $fechaVencimiento, bool $vencido) {
        $hoy = Carbon::parse($instante, $zona);

        expect(loteEnMemoria($fechaVencimiento)->estaVencido($hoy))->toBe($vencido);
    })->with([
        // 23:30 Bogotá del 04 = 04:30 UTC del 05 = 13:30 Tokio del 05.
        '23:30 Bogotá, vence el 05 (mañana)' => ['2026-11-04 23:30:00', RN01_ZONA, '2026-11-05', false],
        '04:30 UTC del 05, vence el 05 (mañana en Bogotá)' => ['2026-11-05 04:30:00', 'UTC', '2026-11-05', false],
        '13:30 Tokio del 05, vence el 05 (mañana en Bogotá)' => ['2026-11-05 13:30:00', 'Asia/Tokyo', '2026-11-05', false],
        '23:30 Bogotá, vence el 04 (hoy)' => ['2026-11-04 23:30:00', RN01_ZONA, '2026-11-04', true],
        '04:30 UTC del 05, vence el 04 (hoy en Bogotá)' => ['2026-11-05 04:30:00', 'UTC', '2026-11-04', true],
        // 00:30 Bogotá del 05 = 05:30 UTC del 05: ya es el 05 en Bogotá.
        '00:30 Bogotá del 05, vence el 05 (hoy)' => ['2026-11-05 00:30:00', RN01_ZONA, '2026-11-05', true],
        '05:30 UTC del 05, vence el 05 (hoy en Bogotá)' => ['2026-11-05 05:30:00', 'UTC', '2026-11-05', true],
        // 04:59:59 UTC del 05 = 23:59:59 Bogotá del 04; 05:00:00 UTC = 00:00 Bogotá del 05.
        '04:59:59 UTC del 05, vence el 05 (aún mañana en Bogotá)' => ['2026-11-05 04:59:59', 'UTC', '2026-11-05', false],
        '05:00:00 UTC del 05, vence el 05 (ya hoy en Bogotá)' => ['2026-11-05 05:00:00', 'UTC', '2026-11-05', true],
        // 20:00 Bogotá del 04 = 01:00 UTC del 05: en UTC ya sería el 05, en Bogotá no.
        '01:00 UTC del 05 (20:00 Bogotá del 04), vence el 05' => ['2026-11-05 01:00:00', 'UTC', '2026-11-05', false],
    ]);

    it('RN-01-AC4 zona horaria: el reloj congelado (travelTo) a las 23:30 de Bogotá da hoy = día de Bogotá', function () {
        $this->travelTo(Carbon::parse('2026-11-05 04:30:00', 'UTC'));

        expect(loteEnMemoria('2026-11-05')->estaVencido(now()))->toBeFalse()
            ->and(loteEnMemoria('2026-11-05')->estaVencido(now('UTC')))->toBeFalse()
            ->and(loteEnMemoria('2026-11-04')->estaVencido(now()))->toBeTrue()
            ->and(loteEnMemoria('2026-11-04')->estaVencido(now('UTC')))->toBeTrue();
    });

    it('RN-01-AC4: evaluar no modifica la fecha "hoy" recibida ni la fecha del lote', function () {
        $hoy = Carbon::parse('2026-11-05 04:30:00', 'UTC');
        $lote = loteEnMemoria('2026-11-05');

        $lote->estaVencido($hoy);

        expect($hoy->toIso8601String())->toBe('2026-11-05T04:30:00+00:00')
            ->and($hoy->getTimezone()->getName())->toBe('UTC')
            ->and($lote->fecha_vencimiento->toDateString())->toBe('2026-11-05');
    });
});
