<?php

declare(strict_types=1);

use App\Enums\EstadoDispensacion;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\DispensacionItem;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dispensacion\SalidaStock;
use App\Services\Inventario\RegistradorKardex;
use App\Support\Negocio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * RN-03 — Stock nunca negativo, ni con concurrencia (AC1, AC2, AC4, AC5).
 * AC3 (procesos separados, sin transacción envolvente) vive en
 * tests/Feature/SinTransaccion/RN03UltimaUnidadConcurrenteTest.php.
 *
 * Supuestos: S-01 (vence hoy = vencido), S-02 (409 aunque haya vencido; vencido_no_dispensable),
 * S-04 (todo o nada), S-42 (el 409 informa el primer ítem que no alcanza; todos los ítems se evalúan
 * antes de escribir).
 *
 * El stock se crea por el camino real (RegistradorKardex, ENTRADA en transacción con la existencia
 * bloqueada) para que el kardex cuadre desde 0.
 */

const RN03_URI = '/api/v1/dispensaciones';
const RN03_URI_AJUSTES = '/api/v1/inventario/ajustes';
const RN03_HOY = '2026-11-04';
const RN03_MOTIVO = 'Conteo físico con faltante RN-03';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn03)
|--------------------------------------------------------------------------
*/

function rn03Congelar(): void
{
    test()->travelTo(Carbon::parse(RN03_HOY.' 10:00:00', 'America/Bogota'));
}

/** Fecha Y-m-d a N días del "hoy" congelado. */
function rn03Dia(int $dias): string
{
    return Carbon::parse(RN03_HOY, 'America/Bogota')->addDays($dias)->toDateString();
}

function rn03Regente(): User
{
    return User::query()->where('email', 'rn03-regente-servicio@farma.test')->first()
        ?? usuarioConRol('regente_farmacia', ['email' => 'rn03-regente-servicio@farma.test']);
}

/** Lote nuevo del producto (vence en `dias` desde hoy) con `cantidad` en la bodega por una ENTRADA real. */
function rn03Stock(Bodega $bodega, Producto $producto, int $dias, int $cantidad): Existencia
{
    $lote = Lote::factory()->create(['producto_id' => $producto->id, 'fecha_vencimiento' => rn03Dia($dias)]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $regente = rn03Regente();
        DB::transaction(function () use ($existencia, $cantidad, $regente): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
        });
    }

    return $existencia->refresh();
}

/**
 * Cuerpo de dispensación con una prescripción vigente holgada (1000 u. por ítem) para que solo el
 * stock decida.
 *
 * @param  list<array{0: int, 1: int}>  $items  [[producto_id, cantidad], …]
 * @return array{cuerpo: array<string, mixed>, items: list<int>}
 */
function rn03Cuerpo(int $bodegaId, array $items): array
{
    $prescripcion = Prescripcion::factory()->create();
    $ids = [];
    $lineas = [];
    foreach ($items as [$productoId, $cantidad]) {
        $item = PrescripcionItem::factory()->create([
            'prescripcion_id' => $prescripcion->id,
            'producto_id' => $productoId,
            'cantidad' => 1000,
        ]);
        $ids[] = $item->id;
        $lineas[] = ['prescripcion_item_id' => $item->id, 'cantidad' => $cantidad];
    }

    return [
        'cuerpo' => [
            'paciente_id' => $prescripcion->paciente_id,
            'prescripcion_id' => $prescripcion->id,
            'bodega_id' => $bodegaId,
            'items' => $lineas,
        ],
        'items' => $ids,
    ];
}

/** @param  list<array{0: int, 1: int}>  $items */
function rn03Dispensar(int $bodegaId, array $items): TestResponse
{
    return test()->postJson(RN03_URI, rn03Cuerpo($bodegaId, $items)['cuerpo'], cabecerasDispensacion());
}

function rn03Ajuste(int $existenciaId, int $cantidad): TestResponse
{
    return test()->postJson(RN03_URI_AJUSTES, [
        'existencia_id' => $existenciaId,
        'cantidad' => $cantidad,
        'motivo' => RN03_MOTIVO,
    ]);
}

function rn03StockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/** @return array<string, mixed> */
function rn03Instantanea(): array
{
    $filas = fn (string $tabla): array => DB::table($tabla)->orderBy('id')->get()
        ->map(fn (object $f): array => (array) $f)->all();

    return [
        'existencias' => $filas('existencias'),
        'kardex' => $filas('movimientos_kardex'),
        'dispensaciones' => $filas('dispensaciones'),
        'dispensacion_items' => $filas('dispensacion_items'),
        'dispensacion_detalles' => $filas('dispensacion_detalles'),
    ];
}

/** Ejecuta SQL en un savepoint y devuelve el SQLSTATE si falló (null si no falló). */
function rn03Sqlstate(Closure $operacion): ?string
{
    try {
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** Cadena de saldos de la existencia desde 0 (orden created_at, id); el último = existencias.cantidad. */
function rn03AfirmarCadena(Existencia $existencia): void
{
    $movimientos = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)
        ->orderBy('created_at')->orderBy('id')->get()->all();

    $saldo = 0;
    foreach ($movimientos as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existencia->id}, movimiento #{$i}: hueco en la cadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }

    expect($saldo)->toBe(rn03StockDe($existencia));
}

/** 409 STOCK_INSUFICIENTE con solicitado / disponible / vencido_no_dispensable (RN-03-AC2, S-02, S-42). */
function rn03AfirmarInsuficiente(TestResponse $r, int $solicitado, int $disponible, int $vencido): void
{
    esperarError($r, 409, 'STOCK_INSUFICIENTE');
    expect($r->json('error.detalles.solicitado'))->toBe($solicitado)
        ->and($r->json('error.detalles.disponible'))->toBe($disponible)
        ->and($r->json('error.detalles.vencido_no_dispensable'))->toBe($vencido);
}

/** Un 500 sin traza, SQL, nombres de tabla ni el texto del fallo inyectado. */
function rn03AfirmarErrorInternoLimpio(TestResponse $r): void
{
    esperarError($r, 500, 'ERROR_INTERNO');
    $texto = mb_strtolower((string) $r->getContent());
    foreach (['sqlstate', 'insert', 'update', 'select', 'movimientos_kardex', 'dispensacion_detalles', 'existencias', 'fallo inyectado', 'trace', '.php', 'exception'] as $prohibido) {
        expect($texto)->not->toContain($prohibido);
    }
}

/**
 * Trigger temporal BEFORE INSERT en `tabla` que aborta la transacción. Con `soloTrasDescontar`, solo
 * aborta si la existencia del movimiento YA tiene el saldo posterior (el stock se descontó antes en la
 * misma transacción): así el test demuestra que el fallo ocurre después de descontar. Con `loteId`,
 * solo aborta el tramo de ese lote. Con `sqlstate`, el error lleva ese ERRCODE (por defecto P0001,
 * el de un RAISE EXCEPTION sin código).
 */
function rn03InyectarFallo(string $tabla, bool $soloTrasDescontar = false, ?int $loteId = null, string $sqlstate = 'P0001'): void
{
    $condiciones = ['TRUE'];
    if ($soloTrasDescontar) {
        $condiciones[] = '(SELECT e.cantidad FROM existencias e WHERE e.id = NEW.existencia_id) = NEW.saldo_posterior';
    }
    if ($loteId !== null) {
        $condiciones[] = 'NEW.lote_id = '.$loteId;
    }
    $condicion = implode(' AND ', $condiciones);

    DB::unprepared(<<<SQL
        CREATE OR REPLACE FUNCTION rn03_test_falla() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            IF {$condicion} THEN
                RAISE EXCEPTION 'fallo inyectado por el test RN-03-AC5' USING ERRCODE = '{$sqlstate}';
            END IF;
            RETURN NEW;
        END;
        \$\$;
        CREATE TRIGGER rn03_test_falla BEFORE INSERT ON {$tabla}
            FOR EACH ROW EXECUTE FUNCTION rn03_test_falla();
        SQL);
}

function rn03QuitarFallo(string $tabla): void
{
    DB::unprepared("DROP TRIGGER IF EXISTS rn03_test_falla ON {$tabla}; DROP FUNCTION IF EXISTS rn03_test_falla();");
}

/*
|--------------------------------------------------------------------------
| RN-03-AC1: CHECK en la BD
|--------------------------------------------------------------------------
*/

describe('RN-03-AC1 CHECK de cantidades en PostgreSQL', function () {
    beforeEach(fn () => rn03Congelar());

    it('RN-03-AC1: con una existencia en 1, un UPDATE directo a cantidad negativa → SQLSTATE 23514 y la fila queda intacta', function (string $sql) {
        $existencia = rn03Stock(Bodega::factory()->create(), Producto::factory()->create(), 200, 1);
        $otra = rn03Stock(Bodega::factory()->create(), Producto::factory()->create(), 200, 5);
        $antes = rn03Instantanea();

        $estado = rn03Sqlstate(fn () => DB::update(str_replace(':id', (string) $existencia->id, $sql)));

        expect($estado)->toBe('23514')
            ->and(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe(1)
            ->and(rn03StockDe($otra))->toBe(5);
    })->with([
        'SET cantidad = -1 (spec)' => ['UPDATE existencias SET cantidad = -1 WHERE id = :id'],
        'SET cantidad = cantidad - 2' => ['UPDATE existencias SET cantidad = cantidad - 2 WHERE id = :id'],
        'SET al mínimo integer' => ['UPDATE existencias SET cantidad = -2147483648 WHERE id = :id'],
        'sin WHERE (todas): ninguna cambia' => ['UPDATE existencias SET cantidad = cantidad - 2'],
    ]);

    it('RN-03-AC1 frontera: llevar una existencia exactamente a 0 por SQL sí se permite (CHECK cantidad >= 0)', function () {
        $existencia = rn03Stock(Bodega::factory()->create(), Producto::factory()->create(), 200, 1);

        expect(rn03Sqlstate(fn () => DB::update('UPDATE existencias SET cantidad = 0 WHERE id = ?', [$existencia->id])))->toBeNull()
            ->and(rn03StockDe($existencia))->toBe(0);
    });

    it('RN-03-AC1: un INSERT directo de existencia con cantidad negativa → 23514 y no se crea la fila; con 0 sí se crea', function (int $cantidad, ?string $sqlstate) {
        $lote = Lote::factory()->create(['fecha_vencimiento' => rn03Dia(200)]);
        $bodega = Bodega::factory()->create();
        $antes = DB::table('existencias')->count();

        $estado = rn03Sqlstate(fn () => DB::insert(
            'INSERT INTO existencias (bodega_id, producto_id, lote_id, cantidad, created_at, updated_at) VALUES (?, ?, ?, ?, now(), now())',
            [$bodega->id, $lote->producto_id, $lote->id, $cantidad],
        ));

        expect($estado)->toBe($sqlstate)
            ->and(DB::table('existencias')->count())->toBe($sqlstate === null ? $antes + 1 : $antes);
    })->with([
        '-1' => [-1, '23514'],
        'mínimo integer' => [-2147483648, '23514'],
        '0 (frontera permitida)' => [0, null],
    ]);

    it('RN-03-AC1: dispensacion_detalles y dispensacion_items rechazan cantidad 0 o negativa por INSERT y por UPDATE (23514); la fila real se reinserta con su cantidad', function (string $tabla, string $operacion, int $cantidad) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn03Stock($bodega, $producto, 200, 10);
        actuarComo('auxiliar_farmacia');
        rn03Dispensar($bodega->id, [[$producto->id, 3]])->assertCreated();

        $detalle = (array) DB::table('dispensacion_detalles')->sole();
        $item = (array) DB::table('dispensacion_items')->sole();
        $fila = $tabla === 'dispensacion_detalles' ? $detalle : $item;
        $antes = rn03Instantanea();

        $estado = rn03Sqlstate(function () use ($tabla, $operacion, $cantidad, $detalle, $item, $fila): void {
            if ($operacion === 'UPDATE') {
                DB::table($tabla)->where('id', $fila['id'])->update(['cantidad' => $cantidad]);

                return;
            }
            // INSERT: se borra la fila real (y su detalle si es el ítem) y se reinserta idéntica salvo la cantidad,
            // para que el CHECK sea la única restricción que puede fallar.
            DB::table('dispensacion_detalles')->where('id', $detalle['id'])->delete();
            if ($tabla === 'dispensacion_items') {
                DB::table('dispensacion_items')->where('id', $item['id'])->delete();
                DB::table('dispensacion_items')->insert(['cantidad' => $cantidad] + $item);
                DB::table('dispensacion_detalles')->insert($detalle);
            } else {
                DB::table('dispensacion_detalles')->insert(['cantidad' => $cantidad] + $detalle);
            }
        });

        expect($estado)->toBe('23514')
            ->and(rn03Instantanea())->toBe($antes);

        // Control positivo: la misma operación con la cantidad original sí pasa (el CHECK era la única causa).
        $original = (int) $fila['cantidad'];
        expect(rn03Sqlstate(function () use ($tabla, $operacion, $original, $detalle, $item, $fila): void {
            if ($operacion === 'UPDATE') {
                DB::table($tabla)->where('id', $fila['id'])->update(['cantidad' => $original]);

                return;
            }
            DB::table('dispensacion_detalles')->where('id', $detalle['id'])->delete();
            if ($tabla === 'dispensacion_items') {
                DB::table('dispensacion_items')->where('id', $item['id'])->delete();
                DB::table('dispensacion_items')->insert($item);
            }
            DB::table('dispensacion_detalles')->insert($detalle);
        }))->toBeNull();
    })->with(['dispensacion_detalles', 'dispensacion_items'])
        ->with(['INSERT', 'UPDATE'])
        ->with(['0' => [0], '-1' => [-1], 'mínimo integer' => [-2147483648]]);
});

/*
|--------------------------------------------------------------------------
| RN-03-AC2: insuficiente → 409 y todo o nada
|--------------------------------------------------------------------------
*/

describe('RN-03-AC2 stock insuficiente, todo o nada', function () {
    beforeEach(fn () => rn03Congelar());

    it('RN-03-AC2: stock vigente total 4 en dos lotes, dispensar 5 → 409 STOCK_INSUFICIENTE con solicitado 5 y disponible 4; sin dispensación, ítem, detalle ni kardex; ambos lotes intactos', function (int $primero, int $segundo, bool $tardioCreadoPrimero) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        // El orden de creación (id) no debe influir.
        [$diasA, $diasB] = $tardioCreadoPrimero ? [300, 40] : [40, 300];
        $a = rn03Stock($bodega, $producto, $diasA, $primero);
        $b = rn03Stock($bodega, $producto, $diasB, $segundo);
        actuarComo('auxiliar_farmacia');
        $antes = rn03Instantanea();

        $r = rn03Dispensar($bodega->id, [[$producto->id, 5]]);

        rn03AfirmarInsuficiente($r, 5, 4, 0);
        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($a))->toBe($primero)
            ->and(rn03StockDe($b))->toBe($segundo)
            ->and(DB::table('dispensaciones')->count())->toBe(0)
            ->and(DB::table('dispensacion_items')->count())->toBe(0)
            ->and(DB::table('dispensacion_detalles')->count())->toBe(0)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(0);
    })->with([
        '1 + 3' => [1, 3, false],
        '2 + 2' => [2, 2, false],
        '3 + 1, lote tardío creado primero' => [3, 1, true],
    ]);

    it('RN-03-AC2 frontera: con el mismo stock de 4, pedir exactamente 4 → 201 y ambos lotes en 0 (nunca negativos); luego 1 más → 409 con disponible 0', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $a = rn03Stock($bodega, $producto, 40, 1);
        $b = rn03Stock($bodega, $producto, 300, 3);
        actuarComo('auxiliar_farmacia');

        rn03Dispensar($bodega->id, [[$producto->id, 4]])->assertCreated();

        expect(rn03StockDe($a))->toBe(0)
            ->and(rn03StockDe($b))->toBe(0)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(2);
        rn03AfirmarCadena($a);
        rn03AfirmarCadena($b);

        $antes = rn03Instantanea();
        rn03AfirmarInsuficiente(rn03Dispensar($bodega->id, [[$producto->id, 1]]), 1, 0, 0);
        expect(rn03Instantanea())->toBe($antes);
    });

    it('RN-03-AC2 S-02: el stock vencido (incluido el que vence hoy) no cuenta como disponible: 4 vigentes + vencidos, pedir 5 → 409 disponible 4 y vencido_no_dispensable exacto', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn03Stock($bodega, $producto, -1, 6);   // venció ayer
        rn03Stock($bodega, $producto, 40, 1);
        rn03Stock($bodega, $producto, 0, 7);    // vence hoy (S-01)
        rn03Stock($bodega, $producto, 300, 3);
        actuarComo('regente_farmacia');
        $antes = rn03Instantanea();

        rn03AfirmarInsuficiente(rn03Dispensar($bodega->id, [[$producto->id, 5]]), 5, 4, 13);
        expect(rn03Instantanea())->toBe($antes);
    });

    it('RN-03-AC2: tras una dispensación que deja 4 en dos lotes, pedir 5 → 409 con disponible 4 (calculado sobre el stock actual) y nada cambia', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $a = rn03Stock($bodega, $producto, 40, 3);
        $b = rn03Stock($bodega, $producto, 300, 3);
        actuarComo('auxiliar_farmacia');
        rn03Dispensar($bodega->id, [[$producto->id, 2]])->assertCreated();
        expect(rn03StockDe($a))->toBe(1)->and(rn03StockDe($b))->toBe(3);
        $antes = rn03Instantanea();

        rn03AfirmarInsuficiente(rn03Dispensar($bodega->id, [[$producto->id, 5]]), 5, 4, 0);

        expect(rn03Instantanea())->toBe($antes)
            ->and(DB::table('dispensaciones')->count())->toBe(1);
    });

    it('RN-03-AC2 S-04 S-42: con varios ítems, si solo el segundo no alcanza → 409 que informa el segundo ítem y no se descuenta nada (tampoco el primero)', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $p1a = rn03Stock($bodega, $p1, 30, 2);
        $p1b = rn03Stock($bodega, $p1, 200, 10);
        $p2a = rn03Stock($bodega, $p2, 40, 1);
        $p2b = rn03Stock($bodega, $p2, 300, 3);
        actuarComo('auxiliar_farmacia');
        $antes = rn03Instantanea();

        ['cuerpo' => $cuerpo, 'items' => $items] = rn03Cuerpo($bodega->id, [[$p1->id, 5], [$p2->id, 5]]);
        $r = $this->postJson(RN03_URI, $cuerpo, cabecerasDispensacion());

        rn03AfirmarInsuficiente($r, 5, 4, 0);
        expect($r->json('error.detalles.producto_id'))->toBe($p2->id)
            ->and($r->json('error.detalles.prescripcion_item_id'))->toBe($items[1])
            ->and(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($p1a))->toBe(2)
            ->and(rn03StockDe($p1b))->toBe(10)
            ->and(rn03StockDe($p2a))->toBe(1)
            ->and(rn03StockDe($p2b))->toBe(3);
    });

    it('RN-03-AC2 S-42: si fallan varios ítems, el 409 informa el primero en el orden de la petición; nada cambia', function (bool $invertir) {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $p3 = Producto::factory()->create();
        rn03Stock($bodega, $p1, 50, 20);
        rn03Stock($bodega, $p2, 40, 1);
        rn03Stock($bodega, $p2, 300, 3);
        rn03Stock($bodega, $p3, 60, 2);
        actuarComo('regente_farmacia');
        $antes = rn03Instantanea();

        // p1 cabe; p2 pide 5 con 4; p3 pide 9 con 2.
        $lineas = $invertir
            ? [[$p1->id, 4], [$p3->id, 9], [$p2->id, 5]]
            : [[$p1->id, 4], [$p2->id, 5], [$p3->id, 9]];
        ['cuerpo' => $cuerpo, 'items' => $items] = rn03Cuerpo($bodega->id, $lineas);
        $r = $this->postJson(RN03_URI, $cuerpo, cabecerasDispensacion());

        [$producto, $solicitado, $disponible] = $invertir ? [$p3->id, 9, 2] : [$p2->id, 5, 4];
        rn03AfirmarInsuficiente($r, $solicitado, $disponible, 0);
        expect($r->json('error.detalles.producto_id'))->toBe($producto)
            ->and($r->json('error.detalles.prescripcion_item_id'))->toBe($items[1])
            ->and(rn03Instantanea())->toBe($antes);
    })->with(['p2 antes que p3' => [false], 'p3 antes que p2' => [true]]);
});

/*
|--------------------------------------------------------------------------
| RN-03-AC4: ajuste negativo mayor que la existencia
|--------------------------------------------------------------------------
*/

describe('RN-03-AC4 ajuste negativo', function () {
    beforeEach(fn () => rn03Congelar());

    // Equivalente en RN-06: 'RN-06-AC6 RN-03-AC4 S-32: un ajuste negativo mayor que la existencia → 409 …'
    // (tests/Feature/RN06KardexTest.php). Este lo repite desde RN-03 y añade fronteras.
    it('RN-03-AC4: un ajuste negativo mayor que la existencia → 409 STOCK_INSUFICIENTE, sin movimiento y stock igual', function (int $inicial, int $cantidad) {
        $existencia = rn03Stock(Bodega::factory()->create(), Producto::factory()->create(), 200, $inicial);
        actuarComo('regente_farmacia');
        $antes = rn03Instantanea();

        esperarError(rn03Ajuste($existencia->id, $cantidad), 409, 'STOCK_INSUFICIENTE');

        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe($inicial)
            ->and(DB::table('movimientos_kardex')->where('tipo', 'AJUSTE')->count())->toBe(0);
    })->with([
        'existencia + 1 (1 → −2)' => [1, -2],
        'existencia + 1 (4 → −5)' => [4, -5],
        'existencia en 0, −1' => [0, -1],
        'muy por encima' => [4, -1000],
    ]);

    it('RN-03-AC4 frontera: un ajuste negativo igual a la existencia deja 0 exacto (201) y un AJUSTE encadenado; el siguiente −1 → 409', function () {
        $existencia = rn03Stock(Bodega::factory()->create(), Producto::factory()->create(), 200, 4);
        actuarComo('regente_farmacia');

        rn03Ajuste($existencia->id, -4)->assertCreated();
        expect(rn03StockDe($existencia))->toBe(0)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'AJUSTE')->count())->toBe(1);
        rn03AfirmarCadena($existencia);

        $antes = rn03Instantanea();
        esperarError(rn03Ajuste($existencia->id, -1), 409, 'STOCK_INSUFICIENTE');
        expect(rn03Instantanea())->toBe($antes);
    });

    it('RN-03-AC4: el ajuste se valida contra SU existencia: otro lote del mismo producto en la bodega con stock de sobra no lo cubre', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $corta = rn03Stock($bodega, $producto, 40, 2);
        $larga = rn03Stock($bodega, $producto, 300, 50);
        actuarComo('regente_farmacia');
        $antes = rn03Instantanea();

        esperarError(rn03Ajuste($corta->id, -3), 409, 'STOCK_INSUFICIENTE');

        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($corta))->toBe(2)
            ->and(rn03StockDe($larga))->toBe(50);
    });
});

/*
|--------------------------------------------------------------------------
| RN-03-AC5: fallo entre el descuento y el kardex → rollback completo
|--------------------------------------------------------------------------
*/

describe('RN-03-AC5 atomicidad de la salida de stock', function () {
    beforeEach(fn () => rn03Congelar());

    it('RN-03-AC5: si falla el INSERT del kardex después de descontar el stock, la petición responde 500 limpio y no queda stock descontado ni dispensación, ítem, detalle o movimiento', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn03Stock($bodega, $producto, 200, 5);
        actuarComo('auxiliar_farmacia');
        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true);
        $antes = rn03Instantanea();

        $r = rn03Dispensar($bodega->id, [[$producto->id, 2]]);

        rn03AfirmarErrorInternoLimpio($r);
        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe(5)
            ->and(DB::table('dispensaciones')->count())->toBe(0)
            ->and(DB::table('dispensacion_items')->count())->toBe(0)
            ->and(DB::table('dispensacion_detalles')->count())->toBe(0)
            ->and(DB::table('movimientos_kardex')->count())->toBe(1);

        // Sin el fallo, la misma operación funciona y la cadena sigue cuadrando (el trigger era la única causa).
        rn03QuitarFallo('movimientos_kardex');
        rn03Dispensar($bodega->id, [[$producto->id, 2]])->assertCreated();
        expect(rn03StockDe($existencia))->toBe(3);
        rn03AfirmarCadena($existencia);
    });

    it('RN-03-AC5 cruce de lotes: si el primer tramo ya descontó y escribió su kardex y falla el del segundo lote, se revierte todo (también el primer tramo)', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $pronto = rn03Stock($bodega, $producto, 30, 2);
        $tarde = rn03Stock($bodega, $producto, 300, 5);
        actuarComo('auxiliar_farmacia');
        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true, loteId: $tarde->lote_id);
        $antes = rn03Instantanea();

        rn03AfirmarErrorInternoLimpio(rn03Dispensar($bodega->id, [[$producto->id, 4]]));

        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($pronto))->toBe(2)
            ->and(rn03StockDe($tarde))->toBe(5);

        rn03QuitarFallo('movimientos_kardex');
        rn03Dispensar($bodega->id, [[$producto->id, 4]])->assertCreated();
        expect(rn03StockDe($pronto))->toBe(0)->and(rn03StockDe($tarde))->toBe(3);
        rn03AfirmarCadena($pronto);
        rn03AfirmarCadena($tarde);
    });

    it('RN-03-AC5 varios ítems: si el primer producto ya se descontó y falla el kardex del segundo, ninguno queda descontado', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $e1 = rn03Stock($bodega, $p1, 100, 6);
        $e2 = rn03Stock($bodega, $p2, 100, 6);
        actuarComo('regente_farmacia');
        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true, loteId: $e2->lote_id);
        $antes = rn03Instantanea();

        rn03AfirmarErrorInternoLimpio(rn03Dispensar($bodega->id, [[$p1->id, 2], [$p2->id, 2]]));

        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($e1))->toBe(6)
            ->and(rn03StockDe($e2))->toBe(6);
        rn03QuitarFallo('movimientos_kardex');
    });

    it('RN-03-AC5: si el fallo llega aún más tarde (al guardar el dispensacion_detalle, con stock y kardex ya escritos) también se revierte todo', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn03Stock($bodega, $producto, 200, 5);
        actuarComo('auxiliar_farmacia');
        rn03InyectarFallo('dispensacion_detalles');
        $antes = rn03Instantanea();

        rn03AfirmarErrorInternoLimpio(rn03Dispensar($bodega->id, [[$producto->id, 2]]));

        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe(5);
        rn03QuitarFallo('dispensacion_detalles');
    });

    it('RN-03-AC5: SalidaStock::descontar es por sí mismo una unidad transaccional: si falla el kardex, la excepción sube y no queda stock descontado, movimiento ni detalle', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $pronto = rn03Stock($bodega, $producto, 30, 2);
        $tarde = rn03Stock($bodega, $producto, 300, 5);
        $usuario = usuarioConRol('auxiliar_farmacia');

        $item = PrescripcionItem::factory()->create(['producto_id' => $producto->id, 'cantidad' => 1000]);
        $dispensacion = new Dispensacion;
        $dispensacion->forceFill([
            'paciente_id' => $item->prescripcion->paciente_id,
            'prescripcion_id' => $item->prescripcion_id,
            'bodega_id' => $bodega->id,
            'dispensado_por_id' => $usuario->id,
            'estado' => EstadoDispensacion::Confirmada,
        ])->save();
        $dItem = new DispensacionItem;
        $dItem->forceFill([
            'dispensacion_id' => $dispensacion->id,
            'prescripcion_id' => $item->prescripcion_id,
            'prescripcion_item_id' => $item->id,
            'producto_id' => $producto->id,
            'cantidad' => 4,
        ])->save();

        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true, loteId: $tarde->lote_id);
        $antes = rn03Instantanea();

        $lanzada = null;
        try {
            app(SalidaStock::class)->descontar($dispensacion, $usuario->id, Negocio::hoy());
        } catch (QueryException $e) {
            $lanzada = $e;
        }

        expect($lanzada)->toBeInstanceOf(QueryException::class)
            ->and(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($pronto))->toBe(2)
            ->and(rn03StockDe($tarde))->toBe(5);

        rn03QuitarFallo('movimientos_kardex');
        $detalles = app(SalidaStock::class)->descontar($dispensacion, $usuario->id, Negocio::hoy());
        expect($detalles)->toHaveCount(2)
            ->and(rn03StockDe($pronto))->toBe(0)
            ->and(rn03StockDe($tarde))->toBe(3);
    });
});

/*
|--------------------------------------------------------------------------
| RN-03-AC3 S-48: conflicto de concurrencia detectado por PostgreSQL
|--------------------------------------------------------------------------
|
| Un interbloqueo (40P01), fallo de serialización (40001) o bloqueo no disponible (55P03) que
| PostgreSQL lanza a mitad de una operación de stock → 409 CONFLICTO_CONCURRENCIA reintentable, con
| Retry-After, sin SQL ni valores, sin cambios; en el log, warning sin SQL ni bindings. El error se
| provoca con un trigger que lanza ese SQLSTATE real después de descontar/sumar el stock (es un
| QueryException genuino de PDO, igual que un interbloqueo real).
*/

/** Envía el log de la aplicación a un archivo temporal propio del test y devuelve su ruta. */
function rn03LogDeTest(): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'farma-log-rn03-');
    config([
        'logging.channels.rn03_s48' => ['driver' => 'single', 'path' => $ruta, 'level' => 'debug'],
        'logging.default' => 'rn03_s48',
    ]);
    // Se borra al terminar el test, también si falla.
    test()->beforeApplicationDestroyed(fn () => @unlink($ruta));

    return $ruta;
}

/** 409 CONFLICTO_CONCURRENCIA reintentable con Retry-After, sin SQL, tablas, valores ni el texto del fallo. */
function rn03AfirmarConflictoConcurrencia(TestResponse $r, string $sqlstate): void
{
    esperarError($r, 409, 'CONFLICTO_CONCURRENCIA');
    expect($r->json('error.detalles'))->toBe(['reintentable' => true])
        ->and($r->headers->get('Retry-After'))->toBe('1');

    $texto = mb_strtolower((string) $r->getContent());
    foreach (['sqlstate', mb_strtolower($sqlstate), 'insert', 'update', 'select', 'movimientos_kardex', 'existencias', 'fallo inyectado', 'trace', '.php', 'exception'] as $prohibido) {
        expect($texto)->not->toContain($prohibido);
    }
}

describe('RN-03-AC3 S-48 conflicto de concurrencia → 409 reintentable', function () {
    beforeEach(fn () => rn03Congelar());

    it('RN-03-AC3 S-48: si PostgreSQL aborta la operación por un conflicto de concurrencia (40P01, 40001, 55P03) tras mover el stock → 409 CONFLICTO_CONCURRENCIA reintentable con Retry-After, sin cambios y log warning sin SQL ni valores; al reintentar sin el conflicto funciona', function (string $operacion, string $sqlstate) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn03Stock($bodega, $producto, 200, 5);

        $enviar = match ($operacion) {
            'dispensacion' => function () use ($bodega, $producto): Closure {
                actuarComo('auxiliar_farmacia');
                $cuerpo = rn03Cuerpo($bodega->id, [[$producto->id, 2]])['cuerpo'];

                return fn (): TestResponse => test()->postJson(RN03_URI, $cuerpo, cabecerasDispensacion());
            },
            'ajuste' => function () use ($existencia): Closure {
                actuarComo('regente_farmacia');

                return fn (): TestResponse => rn03Ajuste($existencia->id, -2);
            },
            'entrada' => function () use ($bodega, $producto, $existencia): Closure {
                actuarComo('regente_farmacia');

                return fn (): TestResponse => test()->postJson('/api/v1/inventario/entradas', [
                    'bodega_id' => $bodega->id,
                    'producto_id' => $producto->id,
                    'lote_id' => $existencia->lote_id,
                    'cantidad' => 2,
                ]);
            },
        };
        $peticion = $enviar();
        $esperado = $operacion === 'entrada' ? 7 : 3;

        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true, sqlstate: $sqlstate);
        $log = rn03LogDeTest();
        $antes = rn03Instantanea();

        $r = $peticion();

        rn03AfirmarConflictoConcurrencia($r, $sqlstate);
        expect(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe(5)
            ->and(DB::table('movimientos_kardex')->count())->toBe(1)
            ->and(DB::table('dispensaciones')->count())->toBe(0);

        $contenido = (string) file_get_contents($log);
        expect($contenido)->toContain('.WARNING:')
            ->and($contenido)->not->toContain('.ERROR:')
            ->and($contenido)->toContain($sqlstate)
            ->and($contenido)->not->toContain('fallo inyectado')
            ->and(strtolower($contenido))->not->toContain('insert into')
            ->and(strtolower($contenido))->not->toContain('update "existencias"')
            ->and($contenido)->not->toContain('"bindings"');

        // Reintentable de verdad: sin el conflicto, la MISMA petición se aplica una sola vez.
        rn03QuitarFallo('movimientos_kardex');
        $peticion()->assertCreated();
        expect(rn03StockDe($existencia))->toBe($esperado);
        rn03AfirmarCadena($existencia);
    })->with(['dispensacion', 'ajuste', 'entrada'])->with([
        'interbloqueo 40P01' => ['40P01'],
        'serialización 40001' => ['40001'],
        'bloqueo no disponible 55P03' => ['55P03'],
    ]);

    it('RN-03-AC3 S-48: otros SQLSTATE no se confunden con un conflicto de concurrencia: 23505 sigue en 500 ERROR_INTERNO (log error) y 22P02 en 422 DATO_INVALIDO; sin cambios', function (string $sqlstate, int $status, string $codigo, string $nivel) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn03Stock($bodega, $producto, 200, 5);
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn03Cuerpo($bodega->id, [[$producto->id, 2]])['cuerpo'];
        rn03InyectarFallo('movimientos_kardex', soloTrasDescontar: true, sqlstate: $sqlstate);
        $log = rn03LogDeTest();
        $antes = rn03Instantanea();

        $r = $this->postJson(RN03_URI, $cuerpo, cabecerasDispensacion());

        esperarError($r, $status, $codigo);
        expect($r->json('error.codigo'))->not->toBe('CONFLICTO_CONCURRENCIA')
            ->and($r->headers->has('Retry-After'))->toBeFalse()
            ->and((array) $r->json('error.detalles'))->not->toHaveKey('reintentable')
            ->and(rn03Instantanea())->toBe($antes)
            ->and(rn03StockDe($existencia))->toBe(5);

        $contenido = (string) file_get_contents($log);
        expect($contenido)->toContain(".{$nivel}:")
            ->and($contenido)->toContain($sqlstate)
            ->and($contenido)->not->toContain('fallo inyectado');
    })->with([
        'unique_violation 23505' => ['23505', 500, 'ERROR_INTERNO', 'ERROR'],
        'invalid_text_representation 22P02' => ['22P02', 422, 'DATO_INVALIDO', 'WARNING'],
    ]);
});
