<?php

declare(strict_types=1);

use App\Exceptions\Dominio\StockInsuficiente;
use App\Models\Existencia;
use App\Models\Lote;
use App\Services\Dispensacion\AsignadorFefo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * RN-02 (y RN-01-AC5 / S-01 / S-02 / S-03 / S-07) — AsignadorFefo::asignar como función pura.
 *
 * Contrato (spec, "Cómo se defiende" de RN-02):
 *   asignar(array $existencias, int $cantidad, CarbonInterface $hoy): array<int lote_id, int cantidad>
 *   - recibe existencias ya bloqueadas (modelos Existencia con la relación `lote` cargada), sin BD;
 *   - orden FEFO: fecha_vencimiento ASC y, a igual fecha, lotes.id ASC (S-03); el array devuelto
 *     conserva ese orden (claves enteras en orden de consumo);
 *   - ignora lotes vencidos (fecha_vencimiento <= hoy de Bogotá, S-01) y existencias en 0;
 *   - todo o nada (S-04): si el vigente no alcanza lanza StockInsuficiente con detalles
 *     `solicitado`, `disponible` (solo vigente) y `vencido_no_dispensable` (S-02);
 *   - cantidad <= 0 → InvalidArgumentException (S-07);
 *   - no muta las existencias recibidas.
 *
 * Los modelos se instancian en memoria (sin persistir). El cast `date` y la zona de negocio
 * (config farma.zona_horaria) necesitan la aplicación arrancada: TestCase de Laravel sin
 * RefreshDatabase, igual que tests/Unit/LoteTest.php. Ninguna consulta toca la BD.
 */
uses(TestCase::class);

const FEFO_ZONA = 'America/Bogota';

/** "Hoy" del negocio para los unitarios: 2026-11-04 a media mañana en Bogotá. */
function fefoHoy(string $fechaHora = '2026-11-04 10:00:00', string $zona = FEFO_ZONA): CarbonImmutable
{
    return CarbonImmutable::parse($fechaHora, $zona);
}

/**
 * Existencia en memoria (bodega 1, producto 1) con su lote cargado. El id de la existencia se
 * elige a propósito distinto del orden de lote para detectar un orden por existencias.id.
 */
function fefoExistencia(int $loteId, string $fechaVencimiento, int $cantidad, ?int $existenciaId = null): Existencia
{
    $lote = new Lote([
        'producto_id' => 1,
        'codigo' => 'L-UNIT-'.$loteId,
        'fecha_vencimiento' => $fechaVencimiento,
    ]);
    $lote->forceFill(['id' => $loteId]);
    $lote->exists = true;
    $lote->syncOriginal();

    $existencia = new Existencia([
        'bodega_id' => 1,
        'producto_id' => 1,
        'lote_id' => $loteId,
    ]);
    $existencia->forceFill(['id' => $existenciaId ?? (10_000 - $loteId), 'cantidad' => $cantidad]);
    $existencia->exists = true;
    $existencia->syncOriginal();
    $existencia->setRelation('lote', $lote);

    return $existencia;
}

/**
 * Salida cruda del asignador (sin normalizar).
 *
 * @param  list<Existencia>  $existencias
 */
function fefoAsignarCrudo(array $existencias, int $cantidad, ?CarbonInterface $hoy = null): array
{
    return app(AsignadorFefo::class)->asignar($existencias, $cantidad, $hoy ?? fefoHoy());
}

/**
 * Asignación como `[lote_id => cantidad]` en orden de consumo.
 *
 * La spec fija esa forma; si el asignador devuelve tramos `list<{lote_id, cantidad, …}>` se
 * convierten aquí (conservando el orden) para que los tests de comportamiento FEFO no dependan de
 * la forma. La forma exigida por la spec la comprueba aparte el test "contrato de la spec".
 *
 * @param  list<Existencia>  $existencias
 * @return array<int, int>
 */
function fefoAsignar(array $existencias, int $cantidad, ?CarbonInterface $hoy = null): array
{
    $crudo = fefoAsignarCrudo($existencias, $cantidad, $hoy);

    if (! array_is_list($crudo) || ! is_array($crudo[0] ?? null)) {
        return $crudo;
    }

    $porLote = [];
    foreach ($crudo as $i => $tramo) {
        expect($tramo)->toHaveKeys(['lote_id', 'cantidad']);
        expect($porLote)->not->toHaveKey($tramo['lote_id'], "el lote {$tramo['lote_id']} aparece en dos tramos (#{$i})");
        $porLote[$tramo['lote_id']] = $tramo['cantidad'];
    }

    return $porLote;
}

/**
 * Ejecuta la asignación esperando StockInsuficiente y devuelve sus detalles.
 *
 * @param  list<Existencia>  $existencias
 * @return array<string, mixed>
 */
function fefoInsuficiente(array $existencias, int $cantidad, ?CarbonInterface $hoy = null): array
{
    try {
        $resultado = fefoAsignar($existencias, $cantidad, $hoy);
    } catch (StockInsuficiente $e) {
        expect($e->codigo())->toBe('STOCK_INSUFICIENTE')
            ->and($e->status())->toBe(409);

        return $e->detalles();
    }

    throw new RuntimeException('Se esperaba StockInsuficiente y se obtuvo la asignación '.json_encode($resultado));
}

/**
 * Lotes del escenario de la spec (RN-02-AC1/AC2) y uno más para cruzar 3 lotes. Ids de lote
 * en orden de "creación" contrario al FEFO.
 *
 * @return array{a: Existencia, b: Existencia, c: Existencia}
 */
function fefoEscenarioTresLotes(): array
{
    return [
        'a' => fefoExistencia(1, '2027-03-01', 5),  // creado primero, vence último
        'b' => fefoExistencia(2, '2026-12-15', 3),  // vence primero
        'c' => fefoExistencia(3, '2027-01-20', 4),  // en medio
    ];
}

describe('RN-02 AsignadorFefo::asignar (función pura)', function () {
    it('RN-02 contrato de la spec: asignar devuelve [lote_id => cantidad] en orden FEFO (no una lista de tramos)', function () {
        $a = fefoExistencia(1, '2027-03-01', 5);
        $b = fefoExistencia(2, '2026-12-15', 3);

        // "Cómo se defiende" de RN-02: AsignadorFefo "devuelve [lote_id => cantidad]".
        expect(fefoAsignarCrudo([$a, $b], 5))->toBe([2 => 3, 1 => 2]);
    });

    it('RN-02-AC1: con A (2027-03-01, 5) y B (2026-12-15, 3), pedir 2 asigna [B => 2] y nada de A', function () {
        $a = fefoExistencia(1, '2027-03-01', 5);
        $b = fefoExistencia(2, '2026-12-15', 3);

        expect(fefoAsignar([$a, $b], 2))->toBe([2 => 2]);
    });

    it('RN-02-AC2: con el mismo stock, pedir 5 asigna [B => 3, A => 2] en ese orden', function () {
        $a = fefoExistencia(1, '2027-03-01', 5);
        $b = fefoExistencia(2, '2026-12-15', 3);

        // toBe compara con ===: también exige el orden de las claves (B antes que A).
        expect(fefoAsignar([$a, $b], 5))->toBe([2 => 3, 1 => 2]);
    });

    it('RN-02-AC1 un lote exacto: pedir exactamente lo que tiene el primer lote lo agota sin tocar el siguiente', function (int $cantidad, array $esperado) {
        $e = fefoEscenarioTresLotes();

        expect(fefoAsignar([$e['a'], $e['b'], $e['c']], $cantidad))->toBe($esperado);
    })->with([
        '1 unidad (dentro de B)' => [1, [2 => 1]],
        'exactamente B (3)' => [3, [2 => 3]],
    ]);

    it('RN-02-AC2 cruce de lotes: la cantidad cruza 1, 2 y 3 lotes en orden de vencimiento (B → C → A)', function (int $cantidad, array $esperado) {
        $e = fefoEscenarioTresLotes();

        $resultado = fefoAsignar([$e['a'], $e['b'], $e['c']], $cantidad);

        expect($resultado)->toBe($esperado)
            ->and(array_sum($resultado))->toBe($cantidad);
    })->with([
        'cruza 1 lote (2)' => [2, [2 => 2]],
        'cruza 2 lotes (4 = B3 + C1)' => [4, [2 => 3, 3 => 1]],
        'agota 2 lotes exactos (7 = B3 + C4)' => [7, [2 => 3, 3 => 4]],
        'cruza 3 lotes (8 = B3 + C4 + A1)' => [8, [2 => 3, 3 => 4, 1 => 1]],
        'suma exacta de todo el stock (12)' => [12, [2 => 3, 3 => 4, 1 => 5]],
    ]);

    it('RN-02-AC2 entrada desordenada: el resultado es el mismo para cualquier permutación de la entrada', function (array $orden) {
        $e = fefoEscenarioTresLotes();
        $entrada = array_map(fn (string $k): Existencia => $e[$k], $orden);

        expect(fefoAsignar($entrada, 8))->toBe([2 => 3, 3 => 4, 1 => 1])
            ->and(fefoAsignar($entrada, 1))->toBe([2 => 1]);
    })->with([
        'a, b, c' => [['a', 'b', 'c']],
        'a, c, b' => [['a', 'c', 'b']],
        'b, a, c' => [['b', 'a', 'c']],
        'b, c, a' => [['b', 'c', 'a']],
        'c, a, b' => [['c', 'a', 'b']],
        'c, b, a' => [['c', 'b', 'a']],
    ]);

    it('RN-02-AC2 FEFO por fecha y no por id de lote ni de existencia: el lote de id mayor y existencia mayor que vence antes va primero', function () {
        $viejo = fefoExistencia(1, '2027-06-01', 10, existenciaId: 1);
        $nuevo = fefoExistencia(99, '2026-11-20', 2, existenciaId: 99);

        expect(fefoAsignar([$viejo, $nuevo], 3))->toBe([99 => 2, 1 => 1]);
    });

    it('RN-02-AC4 S-03: a igual fecha de vencimiento se consume primero el lote de menor lotes.id', function (array $entrada, int $cantidad, array $esperado) {
        expect(fefoAsignar($entrada, $cantidad))->toBe($esperado);
    })->with([
        'menor id entra segundo en el array' => [
            fn () => [fefoExistencia(7, '2027-01-10', 4, existenciaId: 1), fefoExistencia(3, '2027-01-10', 4, existenciaId: 2)],
            2, [3 => 2],
        ],
        'menos que la suma: agota el de menor id y sigue con el otro' => [
            fn () => [fefoExistencia(7, '2027-01-10', 4, existenciaId: 1), fefoExistencia(3, '2027-01-10', 4, existenciaId: 2)],
            6, [3 => 4, 7 => 2],
        ],
        'tres lotes con la misma fecha, desordenados' => [
            fn () => [fefoExistencia(12, '2027-01-10', 1), fefoExistencia(5, '2027-01-10', 1), fefoExistencia(8, '2027-01-10', 1)],
            2, [5 => 1, 8 => 1],
        ],
        'la fecha manda sobre el id: un id menor con fecha posterior va después del empate' => [
            fn () => [fefoExistencia(1, '2027-02-01', 5), fefoExistencia(9, '2027-01-10', 1), fefoExistencia(4, '2027-01-10', 1)],
            3, [4 => 1, 9 => 1, 1 => 1],
        ],
    ]);

    it('RN-02-AC5: las existencias con cantidad 0 se ignoran y nunca aparecen en el resultado', function () {
        $vacia = fefoExistencia(1, '2026-11-10', 0); // la que vence antes, pero sin stock
        $b = fefoExistencia(2, '2026-12-15', 3);
        $a = fefoExistencia(3, '2027-03-01', 5);

        $resultado = fefoAsignar([$vacia, $b, $a], 4);

        expect($resultado)->toBe([2 => 3, 3 => 1])
            ->and($resultado)->not->toHaveKey(1)
            ->and(array_filter($resultado, fn (int $c): bool => $c <= 0))->toBe([]);
    });

    it('RN-02-AC3 S-01: un lote vencido (ayer o antes) y uno que vence hoy se ignoran; uno que vence mañana es el primero', function () {
        $vencido = fefoExistencia(1, '2026-10-01', 10);
        $ayer = fefoExistencia(2, '2026-11-03', 10);
        $hoy = fefoExistencia(3, '2026-11-04', 10);
        $manana = fefoExistencia(4, '2026-11-05', 2);
        $vigente = fefoExistencia(5, '2027-01-01', 10);

        expect(fefoAsignar([$vencido, $ayer, $hoy, $manana, $vigente], 5))->toBe([4 => 2, 5 => 3]);
    });

    it('RN-02-AC3 frontera de vencimiento: ayer y hoy no se toman, mañana sí', function (string $fecha, bool $seToma) {
        $frontera = fefoExistencia(1, $fecha, 5);
        $vigente = fefoExistencia(2, '2027-05-01', 5);

        $resultado = fefoAsignar([$frontera, $vigente], 3);

        expect($resultado)->toBe($seToma ? [1 => 3] : [2 => 3]);
    })->with([
        'vence ayer' => ['2026-11-03', false],
        'vence hoy (S-01: vencido)' => ['2026-11-04', false],
        'vence mañana' => ['2026-11-05', true],
    ]);

    it('RN-02-AC3 lote vencido intercalado: un lote vencido con stock entre vigentes no rompe el orden ni se consume', function () {
        $entrada = [
            fefoExistencia(1, '2027-04-01', 3),
            fefoExistencia(2, '2025-12-31', 50), // vencido, muchísimo stock
            fefoExistencia(3, '2026-12-01', 2),
        ];

        expect(fefoAsignar($entrada, 5))->toBe([3 => 2, 1 => 3]);
    });

    it('RN-02-AC3 S-01 zona horaria: "hoy" es el día de Bogotá aunque $hoy llegue en UTC', function (string $instanteUtc, array $esperado) {
        $hoy = CarbonImmutable::parse($instanteUtc, 'UTC');
        $entrada = [
            fefoExistencia(1, '2026-11-04', 5),
            fefoExistencia(2, '2026-11-05', 5),
            fefoExistencia(3, '2027-01-01', 5),
        ];

        expect(fefoAsignar($entrada, 1, $hoy))->toBe($esperado);
    })->with([
        // 04:30 UTC del 05 = 23:30 del 04 en Bogotá: el lote del 05 vence "mañana" → se toma.
        '2026-11-05 04:30 UTC = 23:30 del 04 en Bogotá' => ['2026-11-05 04:30:00', [2 => 1]],
        // 05:30 UTC del 05 = 00:30 del 05 en Bogotá: el lote del 05 vence "hoy" → vencido.
        '2026-11-05 05:30 UTC = 00:30 del 05 en Bogotá' => ['2026-11-05 05:30:00', [3 => 1]],
        // 23:59:59 del 04 en Bogotá justo antes del cambio de día.
        '2026-11-05 04:59:59 UTC = 23:59:59 del 04 en Bogotá' => ['2026-11-05 04:59:59', [2 => 1]],
        '2026-11-05 05:00:00 UTC = medianoche del 05 en Bogotá' => ['2026-11-05 05:00:00', [3 => 1]],
    ]);

    it('RN-02 S-04: stock vigente insuficiente lanza StockInsuficiente con solicitado y disponible (solo vigente)', function (int $cantidad) {
        $e = fefoEscenarioTresLotes(); // 12 vigentes

        $detalles = fefoInsuficiente([$e['a'], $e['b'], $e['c']], $cantidad);

        expect($detalles['solicitado'] ?? null)->toBe($cantidad)
            ->and($detalles['disponible'] ?? null)->toBe(12)
            ->and($detalles['vencido_no_dispensable'] ?? null)->toBe(0);
    })->with([
        'stock + 1' => [13],
        'mucho más' => [1000],
        'PHP_INT_MAX (sin desbordar la suma)' => [PHP_INT_MAX],
    ]);

    it('RN-01-AC5 S-02: con vencidos y vigentes que no alcanzan, disponible cuenta solo lo vigente y vencido_no_dispensable lo vencido', function () {
        $entrada = [
            fefoExistencia(1, '2026-09-01', 7),  // vencido
            fefoExistencia(2, '2026-11-04', 4),  // vence hoy → vencido (S-01)
            fefoExistencia(3, '2026-11-05', 2),  // vigente
            fefoExistencia(4, '2027-02-01', 1),  // vigente
            fefoExistencia(5, '2026-10-01', 0),  // vencido sin stock: no suma
        ];

        $detalles = fefoInsuficiente($entrada, 4);

        expect($detalles['solicitado'] ?? null)->toBe(4)
            ->and($detalles['disponible'] ?? null)->toBe(3)
            ->and($detalles['vencido_no_dispensable'] ?? null)->toBe(11);
    });

    it('RN-01-AC5: solo lotes vencidos (incluido uno que vence hoy) → StockInsuficiente con disponible 0 y vencido_no_dispensable > 0', function (array $entrada, int $vencido) {
        $detalles = fefoInsuficiente($entrada, 1);

        expect($detalles['disponible'] ?? null)->toBe(0)
            ->and($detalles['vencido_no_dispensable'] ?? null)->toBe($vencido)
            ->and($detalles['vencido_no_dispensable'])->toBeGreaterThan(0)
            ->and($detalles['solicitado'] ?? null)->toBe(1);
    })->with([
        'solo uno que vence hoy' => [fn () => [fefoExistencia(1, '2026-11-04', 6)], 6],
        'vencido + vence hoy' => [fn () => [fefoExistencia(1, '2026-11-03', 2), fefoExistencia(2, '2026-11-04', 5)], 7],
        'vencidos + vigente en 0' => [fn () => [fefoExistencia(1, '2025-01-01', 9), fefoExistencia(2, '2026-11-04', 1), fefoExistencia(3, '2027-01-01', 0)], 10],
    ]);

    it('RN-02 S-04: sin existencias (array vacío) o todas en 0 → StockInsuficiente con disponible 0 y vencido 0', function (array $entrada) {
        $detalles = fefoInsuficiente($entrada, 1);

        expect($detalles['disponible'] ?? null)->toBe(0)
            ->and($detalles['vencido_no_dispensable'] ?? null)->toBe(0)
            ->and($detalles['solicitado'] ?? null)->toBe(1);
    })->with([
        'array vacío' => [fn () => []],
        'todas en 0' => [fn () => [fefoExistencia(1, '2027-01-01', 0), fefoExistencia(2, '2027-02-01', 0)]],
    ]);

    it('RN-02 S-07: cantidad 0 o negativa lanza InvalidArgumentException (y no StockInsuficiente)', function (int $cantidad) {
        $e = fefoEscenarioTresLotes();

        expect(fn () => fefoAsignar([$e['a'], $e['b'], $e['c']], $cantidad))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'cero' => [0],
        'menos uno' => [-1],
        'negativa grande' => [-1000],
        'PHP_INT_MIN' => [PHP_INT_MIN],
    ]);

    it('RN-02 S-07: cantidad 0 o negativa se rechaza aunque no haya existencias', function (int $cantidad) {
        expect(fn () => fefoAsignar([], $cantidad))->toThrow(InvalidArgumentException::class);
    })->with([
        'cero' => [0],
        'menos uno' => [-1],
    ]);

    it('RN-02 función pura: no muta el array de entrada, ni el orden, ni las existencias ni sus lotes', function (int $cantidad, bool $falla) {
        $entrada = [
            fefoExistencia(1, '2027-03-01', 5),
            fefoExistencia(2, '2026-11-04', 9), // vence hoy
            fefoExistencia(3, '2026-12-15', 3),
            fefoExistencia(4, '2027-01-01', 0),
        ];
        $copia = $entrada; // mismas instancias, mismo orden
        $foto = array_map(fn (Existencia $e): array => [
            'existencia' => $e->getAttributes(),
            'lote' => $e->lote->getAttributes(),
        ], $entrada);

        try {
            fefoAsignar($entrada, $cantidad);
            expect($falla)->toBeFalse('se esperaba StockInsuficiente');
        } catch (StockInsuficiente) {
            expect($falla)->toBeTrue('no se esperaba StockInsuficiente');
        }

        expect($entrada)->toBe($copia)
            ->and(array_keys($entrada))->toBe([0, 1, 2, 3]);
        foreach ($entrada as $i => $existencia) {
            expect($existencia->getAttributes())->toBe($foto[$i]['existencia'], "existencia #{$i} modificada")
                ->and($existencia->isDirty())->toBeFalse("existencia #{$i} quedó sucia")
                ->and($existencia->lote->getAttributes())->toBe($foto[$i]['lote'], "lote #{$i} modificado")
                ->and($existencia->lote->isDirty())->toBeFalse("lote #{$i} quedó sucio");
        }
    })->with([
        'asignación que cruza dos lotes' => [5, false],
        'asignación que agota todo lo vigente' => [8, false],
        'insuficiente' => [9, true],
    ]);

    it('RN-02 determinismo: llamadas repetidas con la misma entrada devuelven exactamente lo mismo', function () {
        $e = fefoEscenarioTresLotes();
        $entrada = [$e['c'], $e['a'], $e['b']];

        $primera = fefoAsignar($entrada, 9);

        expect(fefoAsignar($entrada, 9))->toBe($primera)
            ->and($primera)->toBe([2 => 3, 3 => 4, 1 => 2]);
    });

    it('RN-02 invariantes del resultado: claves = lote_id enteros de lotes vigentes, valores > 0, suma = cantidad y nunca más que la existencia', function (int $cantidad) {
        $entrada = [
            fefoExistencia(10, '2027-03-01', 5),
            fefoExistencia(20, '2026-12-15', 3),
            fefoExistencia(30, '2026-11-04', 8),  // vence hoy
            fefoExistencia(40, '2026-12-15', 2),  // empata con 20
            fefoExistencia(50, '2027-08-01', 0),
            fefoExistencia(60, '2028-01-01', 6),
        ];
        $porLote = [];
        foreach ($entrada as $e) {
            $porLote[$e->lote_id] = $e->cantidad;
        }

        $resultado = fefoAsignar($entrada, $cantidad);

        expect(array_sum($resultado))->toBe($cantidad);
        foreach ($resultado as $loteId => $tomado) {
            expect($loteId)->toBeInt()->toBeIn([10, 20, 40, 60])
                ->and($tomado)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual($porLote[$loteId]);
        }
        // Orden FEFO completo: 20 (12-15) → 40 (12-15, id mayor) → 10 (2027-03) → 60 (2028).
        $ordenFefo = [20, 40, 10, 60];
        $claves = array_keys($resultado);
        expect($claves)->toBe(array_slice($ordenFefo, 0, count($claves)));
        // Todos los lotes anteriores al último tocado quedan agotados.
        foreach (array_slice($claves, 0, -1) as $loteId) {
            expect($resultado[$loteId])->toBe($porLote[$loteId]);
        }
    })->with([1, 3, 4, 5, 6, 10, 11, 15, 16]);
});

describe('RN-02 AsignadorFefo: entrada homogénea y pureza', function () {
    it('RN-02 contrato del llamador: lote repetido, productos o bodegas mezclados → InvalidArgumentException', function (Closure $entrada, string $mensaje) {
        expect(fn () => fefoAsignar($entrada(), 1))->toThrow(InvalidArgumentException::class, $mensaje);
    })->with([
        'lote repetido' => [fn () => [
            fefoExistencia(1, '2027-01-01', 5, 101),
            fefoExistencia(1, '2027-01-01', 5, 102),
        ], 'una sola existencia por lote'],
        'productos mezclados' => [fn () => [
            fefoExistencia(1, '2027-01-01', 5),
            fefoExistencia(2, '2027-02-01', 5)->forceFill(['producto_id' => 2]),
        ], 'un solo producto y una sola bodega'],
        'bodegas mezcladas' => [fn () => [
            fefoExistencia(1, '2027-01-01', 5),
            fefoExistencia(2, '2027-02-01', 5)->forceFill(['bodega_id' => 2]),
        ], 'un solo producto y una sola bodega'],
    ]);

    it('RN-02 función pura: con los lotes ya cargados, asignar no hace ninguna consulta (ni al asignar ni al fallar)', function (int $cantidad, bool $falla) {
        $e = fefoEscenarioTresLotes();
        $entrada = [$e['c'], $e['a'], $e['b'], fefoExistencia(9, '2026-11-01', 7)]; // + un vencido
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        try {
            fefoAsignarCrudo($entrada, $cantidad);
            expect($falla)->toBeFalse('se esperaba StockInsuficiente');
        } catch (StockInsuficiente) {
            expect($falla)->toBeTrue('no se esperaba StockInsuficiente');
        }

        expect($consultas)->toBe(0);
    })->with([
        'asigna cruzando lotes' => [5, false],
        'stock insuficiente' => [10_000, true],
    ]);
});
