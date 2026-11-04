<?php

declare(strict_types=1);

use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * RN-01 — Existencia por bodega + producto + lote; lote vencido no se mueve.
 *
 * Alcance de este archivo: AC1, AC2, AC3 (restricciones de BD), AC8 (entradas) y AC9 (consulta).
 * AC4 (Lote::estaVencido) está en tests/Unit/LoteTest.php; AC5–AC7 dependen de RN-02 y RN-07.
 * La concurrencia de entradas está en tests/Feature/SinTransaccion/RN01EntradasConcurrentesTest.php.
 *
 * Contrato de POST /api/v1/inventario/entradas (la spec no lo fija; ver informe):
 *   bodega_id, producto_id, cantidad y el lote como `lote_id` (ya registrado) o como
 *   `lote: {codigo, fecha_vencimiento}` (lote de la compra; se crea si no existe).
 *   S-22 bodega/producto inactivos → 422 VALIDACION · S-23 código existente con otra fecha →
 *   422 LOTE_NO_COINCIDE (detalles lote_id, fecha_registrada, fecha_enviada) · S-24 cantidad
 *   entera 1..1.000.000 · saldo + cantidad > 2.147.483.647 → 422 CANTIDAD_EXCEDE_MAXIMO
 *   (detalles saldo_actual, cantidad, maximo) · 201 = fila de GET /inventario (+ bodega y producto).
 *   S-25 GET /inventario: incluye cantidad 0, 25 por página (máx. 100), id inexistente → vacío.
 */

const RN01_URI_ENTRADAS = '/api/v1/inventario/entradas';
const RN01_URI_INVENTARIO = '/api/v1/inventario';
const RN01_TABLA_KARDEX = 'movimientos_kardex';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn01 para no chocar con funciones globales de otros archivos)
|--------------------------------------------------------------------------
*/

/**
 * Ejecuta SQL que debe fallar dentro de un savepoint (no aborta la transacción de
 * RefreshDatabase) y devuelve el SQLSTATE, o null si no falló.
 */
function rn01Sqlstate(Closure $operacion): ?string
{
    try {
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** Congela el reloj a una hora local de Bogotá ("hoy" de negocio). */
function rn01CongelarHoy(string $fechaHoraBogota = '2026-11-04 10:00:00'): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Fecha (Y-m-d) relativa al "hoy" de Bogotá del reloj congelado. */
function rn01Dia(int $dias): string
{
    return now('America/Bogota')->startOfDay()->addDays($dias)->toDateString();
}

/** Fila cruda de existencias para INSERT directo. @return array<string, mixed> */
function rn01FilaExistencia(int $bodegaId, int $productoId, int $loteId, int $cantidad = 10): array
{
    return [
        'bodega_id' => $bodegaId,
        'producto_id' => $productoId,
        'lote_id' => $loteId,
        'cantidad' => $cantidad,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** Fila cruda de lotes para INSERT directo. @return array<string, mixed> */
function rn01FilaLote(int $productoId, string $codigo, mixed $fechaVencimiento): array
{
    return [
        'producto_id' => $productoId,
        'codigo' => $codigo,
        'fecha_vencimiento' => $fechaVencimiento,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** Existencia con stock inicial fijado (la factory crea sin guarda, `cantidad` incluida). */
function rn01Existencia(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    return Existencia::factory()->paraLote($lote)->create([
        'bodega_id' => $bodega->id,
        'cantidad' => $cantidad,
    ]);
}

function rn01MovimientosKardex(): int
{
    return Schema::hasTable(RN01_TABLA_KARDEX) ? DB::table(RN01_TABLA_KARDEX)->count() : 0;
}

/**
 * Estado completo de inventario: lotes, existencias y número de movimientos de kardex.
 * En los rechazos debe ser idéntico antes y después.
 *
 * @return array<string, mixed>
 */
function rn01Instantanea(): array
{
    return [
        'lotes' => DB::table('lotes')->orderBy('id')
            ->get(['id', 'producto_id', 'codigo', 'fecha_vencimiento'])
            ->map(fn (object $f): array => (array) $f)->all(),
        'existencias' => DB::table('existencias')->orderBy('id')
            ->get(['id', 'bodega_id', 'producto_id', 'lote_id', 'cantidad'])
            ->map(fn (object $f): array => (array) $f)->all(),
        'kardex' => rn01MovimientosKardex(),
    ];
}

/**
 * Si el kardex ya existe (RN-06), la entrada aceptada debe haber dejado exactamente un
 * movimiento ENTRADA (S-17) con la cantidad positiva sobre esa existencia.
 */
function rn01AfirmarKardexEntrada(int $kardexAntes, Existencia $existencia, int $cantidad, int $saldoAnterior): void
{
    if (! Schema::hasTable(RN01_TABLA_KARDEX)) {
        return; // El kardex llega con RN-06 (RN-06-AC1 cubre la entrada); aquí se activa solo.
    }

    expect(rn01MovimientosKardex())->toBe($kardexAntes + 1);

    $movimiento = DB::table(RN01_TABLA_KARDEX)->orderByDesc('id')->first();
    expect($movimiento->tipo)->toBe('ENTRADA')
        ->and((int) $movimiento->cantidad)->toBe($cantidad)
        ->and((int) $movimiento->saldo_anterior)->toBe($saldoAnterior)
        ->and((int) $movimiento->saldo_posterior)->toBe($saldoAnterior + $cantidad);
}

/**
 * Filas de GET /inventario reducidas a "codigo_lote|cantidad" (identificador estable sin
 * depender de campos que la spec no nombra). @return list<string>
 */
function rn01ClavesInventario(TestResponse $respuesta): array
{
    $filas = $respuesta->json('data');
    expect($filas)->toBeArray();

    $claves = array_map(fn (array $f): string => $f['lote']['codigo'].'|'.$f['cantidad'], $filas);
    sort($claves);

    return $claves;
}

/**
 * Escenario fijo de inventario para AC9 (hoy congelado = 2026-11-04 Bogotá).
 *
 *   B1: P1/L1 (vigente) 11 · P1/L2 (vence hoy) 12 · P2/L3 (venció ayer) 13
 *   B2: P1/L1 14 · P2/L4 (vence mañana) 15 · P1/L2 16
 *
 * @return array{bodegas: array<string, Bodega>, productos: array<string, Producto>, lotes: array<string, Lote>}
 */
function rn01EscenarioInventario(): array
{
    rn01CongelarHoy();

    $b1 = Bodega::factory()->create();
    $b2 = Bodega::factory()->create();
    $p1 = Producto::factory()->create();
    $p2 = Producto::factory()->create();

    $l1 = Lote::factory()->create(['producto_id' => $p1->id, 'codigo' => 'RN01-L1', 'fecha_vencimiento' => rn01Dia(200)]);
    $l2 = Lote::factory()->create(['producto_id' => $p1->id, 'codigo' => 'RN01-L2', 'fecha_vencimiento' => rn01Dia(0)]);
    $l3 = Lote::factory()->create(['producto_id' => $p2->id, 'codigo' => 'RN01-L3', 'fecha_vencimiento' => rn01Dia(-1)]);
    $l4 = Lote::factory()->create(['producto_id' => $p2->id, 'codigo' => 'RN01-L4', 'fecha_vencimiento' => rn01Dia(1)]);

    rn01Existencia($b1, $l1, 11);
    rn01Existencia($b1, $l2, 12);
    rn01Existencia($b1, $l3, 13);
    rn01Existencia($b2, $l1, 14);
    rn01Existencia($b2, $l4, 15);
    rn01Existencia($b2, $l2, 16);

    return [
        'bodegas' => ['B1' => $b1, 'B2' => $b2],
        'productos' => ['P1' => $p1, 'P2' => $p2],
        'lotes' => ['L1' => $l1, 'L2' => $l2, 'L3' => $l3, 'L4' => $l4],
    ];
}

/**
 * N existencias en una bodega y un producto, cada una con su lote vigente "RN01-PAG-0001"…
 * (inserción masiva). La cantidad de la i-ésima es i - 1, así que la primera tiene cantidad 0.
 *
 * @return list<string> códigos de lote creados
 */
function rn01ExistenciasMasivas(int $n): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->create();

    $codigos = [];
    for ($i = 1; $i <= $n; $i++) {
        $codigos[] = sprintf('RN01-PAG-%04d', $i);
    }

    DB::table('lotes')->insert(array_map(
        fn (string $codigo): array => rn01FilaLote($producto->id, $codigo, rn01Dia(200)),
        $codigos,
    ));

    $lotes = DB::table('lotes')->where('producto_id', $producto->id)->orderBy('codigo')->pluck('id')->all();
    DB::table('existencias')->insert(array_map(
        fn (int $loteId, int $i): array => rn01FilaExistencia($bodega->id, $producto->id, $loteId, $i),
        $lotes,
        array_keys($lotes),
    ));

    return $codigos;
}

/** Códigos de lote de una página de GET /inventario, en el orden recibido. @return list<string> */
function rn01CodigosPagina(TestResponse $respuesta): array
{
    return array_map(fn (array $f): string => $f['lote']['codigo'], $respuesta->json('data'));
}

/*
|--------------------------------------------------------------------------
| AC1–AC3: restricciones de BD (inserts directos, SQLSTATE real de PostgreSQL)
|--------------------------------------------------------------------------
*/

describe('RN-01 restricciones de BD: identidad de existencia y lote', function () {
    it('RN-01-AC1: una segunda existencia con la misma bodega + producto + lote viola la unicidad (23505) y no se inserta', function (int $cantidadSegunda) {
        $bodega = Bodega::factory()->create();
        $lote = Lote::factory()->create();
        $original = rn01Existencia($bodega, $lote, 7);

        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->insert(
            rn01FilaExistencia($bodega->id, $lote->producto_id, $lote->id, $cantidadSegunda)
        ));

        expect($sqlstate)->toBe('23505')
            ->and(DB::table('existencias')->count())->toBe(1)
            ->and(DB::table('existencias')->where('id', $original->id)->value('cantidad'))->toBe(7);
    })->with([
        'misma cantidad' => [7],
        'otra cantidad' => [99],
        'cantidad cero' => [0],
    ]);

    it('RN-01-AC1: la unicidad también se aplica al crear por Eloquent (no solo por SQL directo)', function () {
        $bodega = Bodega::factory()->create();
        $lote = Lote::factory()->create();
        rn01Existencia($bodega, $lote, 5);

        expect(fn () => DB::transaction(fn () => Existencia::query()->create([
            'bodega_id' => $bodega->id,
            'producto_id' => $lote->producto_id,
            'lote_id' => $lote->id,
        ])))->toThrow(QueryException::class);

        expect(DB::table('existencias')->count())->toBe(1)
            ->and(DB::table('existencias')->value('cantidad'))->toBe(5);
    });

    it('RN-01-AC1: un UPDATE que convierte una existencia en duplicada de otra terna viola la unicidad (23505)', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $lote = Lote::factory()->create();
        rn01Existencia($b1, $lote, 5);
        $otra = rn01Existencia($b2, $lote, 8);

        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->where('id', $otra->id)->update(['bodega_id' => $b1->id]));

        expect($sqlstate)->toBe('23505')
            ->and(DB::table('existencias')->where('id', $otra->id)->value('bodega_id'))->toBe($b2->id);
    });

    it('RN-01-AC1 sin falsos positivos: la unicidad es por la terna completa (cambiar bodega o lote sí se admite)', function () {
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $l1 = Lote::factory()->create(['producto_id' => $producto->id]);
        $l2 = Lote::factory()->create(['producto_id' => $producto->id]);
        rn01Existencia($b1, $l1, 5);

        expect(rn01Sqlstate(fn () => DB::table('existencias')->insert(rn01FilaExistencia($b2->id, $producto->id, $l1->id))))->toBeNull()
            ->and(rn01Sqlstate(fn () => DB::table('existencias')->insert(rn01FilaExistencia($b1->id, $producto->id, $l2->id))))->toBeNull()
            ->and(DB::table('existencias')->count())->toBe(3);
    });

    it('RN-01-AC2: un lote sin fecha_vencimiento es rechazado por NOT NULL (23502) y no se inserta', function (bool $omitirColumna) {
        $producto = Producto::factory()->create();
        $fila = rn01FilaLote($producto->id, 'RN01-SINFECHA', null);
        if ($omitirColumna) {
            unset($fila['fecha_vencimiento']);
        }

        $sqlstate = rn01Sqlstate(fn () => DB::table('lotes')->insert($fila));

        expect($sqlstate)->toBe('23502')
            ->and(DB::table('lotes')->where('codigo', 'RN01-SINFECHA')->exists())->toBeFalse();
    })->with([
        'fecha_vencimiento NULL explícito' => [false],
        'columna fecha_vencimiento omitida' => [true],
    ]);

    it('RN-01-AC2: un UPDATE que deja un lote sin fecha_vencimiento es rechazado (23502)', function () {
        rn01CongelarHoy();
        $lote = Lote::factory()->create(['fecha_vencimiento' => '2027-01-15']);

        $sqlstate = rn01Sqlstate(fn () => DB::table('lotes')->where('id', $lote->id)->update(['fecha_vencimiento' => null]));

        expect($sqlstate)->toBe('23502')
            ->and(DB::table('lotes')->where('id', $lote->id)->value('fecha_vencimiento'))->toBe('2027-01-15');
    });

    it('RN-01-AC2: un lote con código repetido para el mismo producto viola UNIQUE(producto_id, codigo) (23505)', function (string $fechaSegundo) {
        $producto = Producto::factory()->create();
        DB::table('lotes')->insert(rn01FilaLote($producto->id, 'RN01-DUP', '2027-03-01'));

        $sqlstate = rn01Sqlstate(fn () => DB::table('lotes')->insert(rn01FilaLote($producto->id, 'RN01-DUP', $fechaSegundo)));

        expect($sqlstate)->toBe('23505')
            ->and(DB::table('lotes')->where('producto_id', $producto->id)->where('codigo', 'RN01-DUP')->count())->toBe(1)
            ->and(DB::table('lotes')->where('codigo', 'RN01-DUP')->value('fecha_vencimiento'))->toBe('2027-03-01');
    })->with([
        'misma fecha de vencimiento' => ['2027-03-01'],
        'otra fecha de vencimiento' => ['2028-06-30'],
    ]);

    it('RN-01-AC2 sin falsos positivos: el mismo código de lote en otro producto sí se admite', function () {
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        DB::table('lotes')->insert(rn01FilaLote($p1->id, 'RN01-COMPARTIDO', '2027-03-01'));

        $sqlstate = rn01Sqlstate(fn () => DB::table('lotes')->insert(rn01FilaLote($p2->id, 'RN01-COMPARTIDO', '2027-03-01')));

        expect($sqlstate)->toBeNull()
            ->and(DB::table('lotes')->where('codigo', 'RN01-COMPARTIDO')->count())->toBe(2);
    });

    it('RN-01-AC3: una existencia con un lote de P1 y producto P2 viola la FK compuesta (23503) y no se inserta', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $loteDeP1 = Lote::factory()->create(['producto_id' => $p1->id]);

        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->insert(
            rn01FilaExistencia($bodega->id, $p2->id, $loteDeP1->id)
        ));

        expect($sqlstate)->toBe('23503')
            ->and(DB::table('existencias')->count())->toBe(0);
    });

    it('RN-01-AC3: un UPDATE que cambia el producto (o el lote) de una existencia a uno que no casa viola la FK compuesta (23503)', function (string $columna) {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $loteDeP1 = Lote::factory()->create(['producto_id' => $p1->id]);
        $loteDeP2 = Lote::factory()->create(['producto_id' => $p2->id]);
        $existencia = rn01Existencia($bodega, $loteDeP1, 9);

        $nuevoValor = $columna === 'producto_id' ? $p2->id : $loteDeP2->id;
        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->where('id', $existencia->id)->update([$columna => $nuevoValor]));

        $fila = DB::table('existencias')->where('id', $existencia->id)->first();
        expect($sqlstate)->toBe('23503')
            ->and($fila->producto_id)->toBe($p1->id)
            ->and($fila->lote_id)->toBe($loteDeP1->id)
            ->and($fila->cantidad)->toBe(9);
    })->with([
        'cambia producto_id' => ['producto_id'],
        'cambia lote_id' => ['lote_id'],
    ]);

    it('RN-01-AC3: un UPDATE que cambia el producto de un lote con existencias es rechazado (23503) y el lote sigue en su producto', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $lote = Lote::factory()->create(['producto_id' => $p1->id]);
        rn01Existencia($bodega, $lote, 4);

        $sqlstate = rn01Sqlstate(fn () => DB::table('lotes')->where('id', $lote->id)->update(['producto_id' => $p2->id]));

        expect($sqlstate)->toBe('23503')
            ->and(DB::table('lotes')->where('id', $lote->id)->value('producto_id'))->toBe($p1->id);
    });

    it('RN-01-AC3: una existencia con un lote inexistente es rechazada (23503)', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();

        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->insert(
            rn01FilaExistencia($bodega->id, $producto->id, 987654321)
        ));

        expect($sqlstate)->toBe('23503')
            ->and(DB::table('existencias')->count())->toBe(0);
    });

    it('RN-01-AC3 sin falsos positivos: existencia con lote y producto que sí casan se acepta', function () {
        $bodega = Bodega::factory()->create();
        $lote = Lote::factory()->create();

        $sqlstate = rn01Sqlstate(fn () => DB::table('existencias')->insert(
            rn01FilaExistencia($bodega->id, $lote->producto_id, $lote->id, 3)
        ));

        expect($sqlstate)->toBeNull()
            ->and(DB::table('existencias')->where('lote_id', $lote->id)->value('cantidad'))->toBe(3);
    });
});

/*
|--------------------------------------------------------------------------
| AC8: POST /api/v1/inventario/entradas (solo regente, S-17)
|--------------------------------------------------------------------------
*/

describe('RN-01-AC8 entradas de inventario', function () {
    beforeEach(function () {
        rn01CongelarHoy();
        $this->bodega = Bodega::factory()->create();
        $this->producto = Producto::factory()->create();
    });

    it('RN-01-AC8: un regente registra la entrada de un lote nuevo vigente: 201, crea el lote y la existencia con la cantidad', function () {
        actuarComo('regente_farmacia');
        $kardexAntes = rn01MovimientosKardex();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-COMPRA-1', 'fecha_vencimiento' => rn01Dia(180)],
            'cantidad' => 40,
        ]);

        $r->assertCreated();

        $lote = Lote::query()->where('producto_id', $this->producto->id)->where('codigo', 'RN01-COMPRA-1')->sole();
        expect($lote->fecha_vencimiento->toDateString())->toBe(rn01Dia(180));

        $existencia = Existencia::query()
            ->where(['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'lote_id' => $lote->id])
            ->sole();
        expect($existencia->cantidad)->toBe(40)
            ->and(Existencia::query()->count())->toBe(1);

        rn01AfirmarKardexEntrada($kardexAntes, $existencia, 40, 0);
    });

    it('RN-01-AC8: la entrada sobre una existencia que ya existe SUMA a su stock y no crea otra existencia ni otro lote', function (string $forma) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-EXIST', 'fecha_vencimiento' => rn01Dia(90)]);
        $existencia = rn01Existencia($this->bodega, $lote, 25);
        $kardexAntes = rn01MovimientosKardex();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 15];
        $cuerpo += $forma === 'lote_id'
            ? ['lote_id' => $lote->id]
            : ['lote' => ['codigo' => 'RN01-EXIST', 'fecha_vencimiento' => rn01Dia(90)]];

        $this->postJson(RN01_URI_ENTRADAS, $cuerpo)->assertCreated();

        expect($existencia->fresh()->cantidad)->toBe(40)
            ->and(Existencia::query()->count())->toBe(1)
            ->and(Lote::query()->count())->toBe(1);

        rn01AfirmarKardexEntrada($kardexAntes, $existencia, 15, 25);
    })->with([
        'lote referenciado por lote_id' => ['lote_id'],
        'lote referenciado por código y fecha' => ['codigo'],
    ]);

    it('RN-01-AC8: la entrada de un lote ya existente en OTRA bodega crea la existencia en la bodega destino y no toca la otra', function () {
        actuarComo('regente_farmacia');
        $otraBodega = Bodega::factory()->create();
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(60)]);
        $existenciaOtra = rn01Existencia($otraBodega, $lote, 30);

        $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $lote->id,
            'cantidad' => 8,
        ])->assertCreated();

        expect($existenciaOtra->fresh()->cantidad)->toBe(30)
            ->and(Existencia::query()->where(['bodega_id' => $this->bodega->id, 'lote_id' => $lote->id])->sole()->cantidad)->toBe(8)
            ->and(Existencia::query()->count())->toBe(2);
    });

    it('RN-01-AC8: dos entradas sucesivas sobre la misma terna acumulan (5 + 7 = 12) en una sola existencia', function () {
        actuarComo('regente_farmacia');
        $cuerpo = [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-ACUM', 'fecha_vencimiento' => rn01Dia(30)],
        ];

        $this->postJson(RN01_URI_ENTRADAS, $cuerpo + ['cantidad' => 5])->assertCreated();
        $this->postJson(RN01_URI_ENTRADAS, $cuerpo + ['cantidad' => 7])->assertCreated();

        expect(Lote::query()->where('codigo', 'RN01-ACUM')->count())->toBe(1)
            ->and(Existencia::query()->sole()->cantidad)->toBe(12);
    });

    it('RN-01-AC8: lote NUEVO con fecha_vencimiento <= hoy → 422 LOTE_VENCIDO y no se crea lote, existencia ni kardex', function (int $dias) {
        actuarComo('regente_farmacia');
        $otro = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(100)]);
        rn01Existencia($this->bodega, $otro, 50);
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-VENCIDO-NUEVO', 'fecha_vencimiento' => rn01Dia($dias)],
            'cantidad' => 10,
        ]);

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect(rn01Instantanea())->toBe($antes)
            ->and(Lote::query()->where('codigo', 'RN01-VENCIDO-NUEVO')->exists())->toBeFalse();
    })->with([
        'vence hoy (S-01)' => [0],
        'venció ayer' => [-1],
        'venció hace un año' => [-365],
    ]);

    it('RN-01-AC8: lote YA REGISTRADO y vencido (hoy o antes) → 422 LOTE_VENCIDO y su existencia no cambia', function (int $dias, string $forma) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-VENC-REG', 'fecha_vencimiento' => rn01Dia($dias)]);
        rn01Existencia($this->bodega, $lote, 20);
        $antes = rn01Instantanea();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 5];
        $cuerpo += $forma === 'lote_id'
            ? ['lote_id' => $lote->id]
            : ['lote' => ['codigo' => 'RN01-VENC-REG', 'fecha_vencimiento' => rn01Dia($dias)]];

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect(rn01Instantanea())->toBe($antes);
    })->with([
        'vence hoy' => [0],
        'venció ayer' => [-1],
    ])->with([
        'por lote_id' => ['lote_id'],
        'por código y fecha' => ['codigo'],
    ]);

    it('RN-01-AC8 frontera: un lote que vence mañana sí se admite (201)', function (string $forma) {
        actuarComo('regente_farmacia');
        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 3];

        if ($forma === 'lote_id') {
            $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(1)]);
            $cuerpo['lote_id'] = $lote->id;
        } else {
            $cuerpo += ['lote' => ['codigo' => 'RN01-MANANA', 'fecha_vencimiento' => rn01Dia(1)]];
        }

        $this->postJson(RN01_URI_ENTRADAS, $cuerpo)->assertCreated();

        expect(Existencia::query()->sole()->cantidad)->toBe(3)
            ->and(Existencia::query()->sole()->lote->fecha_vencimiento->toDateString())->toBe(rn01Dia(1));
    })->with([
        'lote ya registrado' => ['lote_id'],
        'lote nuevo' => ['codigo'],
    ]);

    it('RN-01-AC8 zona horaria: a las 23:30 de Bogotá (04:30 UTC del día siguiente) "hoy" sigue siendo el día de Bogotá', function (string $fechaVencimiento, int $status) {
        $this->travelTo(Carbon::parse('2026-11-05 04:30:00', 'UTC')); // = 2026-11-04 23:30 Bogotá
        actuarComo('regente_farmacia');
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-TZ', 'fecha_vencimiento' => $fechaVencimiento],
            'cantidad' => 6,
        ]);

        if ($status === 201) {
            $r->assertCreated();
            expect(Existencia::query()->sole()->cantidad)->toBe(6);
        } else {
            esperarError($r, 422, 'LOTE_VENCIDO');
            expect(rn01Instantanea())->toBe($antes);
        }
    })->with([
        'vence el 05 (mañana en Bogotá; hoy en UTC) → se admite' => ['2026-11-05', 201],
        'vence el 04 (hoy en Bogotá) → vencido' => ['2026-11-04', 422],
    ]);

    it('RN-01-AC8: cantidad 0, negativa, decimal o no entera → 422 VALIDACION con detalles.campos.cantidad y sin cambios', function (mixed $cantidad, bool $omitir) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'lote_id' => $lote->id];
        if (! $omitir) {
            $cuerpo['cantidad'] = $cantidad;
        }

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('cantidad')
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'cero' => [0, false],
        'menos uno' => [-1, false],
        'negativa grande' => [-100, false],
        'decimal 1.5' => [1.5, false],
        'decimal como texto "2.5"' => ['2.5', false],
        'decimal 0.5 (redondearía a 0 o 1)' => [0.5, false],
        'texto no numérico' => ['diez', false],
        'cadena vacía' => ['', false],
        'null' => [null, false],
        'arreglo' => [[5], false],
        'booleano true' => [true, false],
        'fuera del rango de integer (2^31)' => [2147483648, false],
        'ausente' => [null, true],
    ]);

    it('RN-01-AC8: lote nuevo con vencimiento ≤ hoy Y cantidad inválida tampoco cambia nada (422)', function () {
        actuarComo('regente_farmacia');
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-DOBLE-MAL', 'fecha_vencimiento' => rn01Dia(-1)],
            'cantidad' => 0,
        ]);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('cantidad')
            ->and(rn01Instantanea())->toBe($antes);
    });

    it('RN-01-AC8: datos de referencia inválidos (bodega/producto/lote inexistentes, sin lote, fecha mal formada) → 422 VALIDACION sin cambios', function (Closure $mutar, string $campo) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        $cuerpo = $mutar([
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-REF', 'fecha_vencimiento' => rn01Dia(120)],
            'cantidad' => 5,
        ], $lote);

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'bodega inexistente' => [fn (array $c) => ['bodega_id' => 987654321] + $c, 'bodega_id'],
        'bodega ausente' => [fn (array $c) => array_diff_key($c, ['bodega_id' => 1]), 'bodega_id'],
        'producto inexistente' => [fn (array $c) => ['producto_id' => 987654321] + $c, 'producto_id'],
        'producto ausente' => [fn (array $c) => array_diff_key($c, ['producto_id' => 1]), 'producto_id'],
        'lote nuevo sin fecha_vencimiento' => [fn (array $c) => ['lote' => ['codigo' => 'RN01-REF']] + $c, 'lote.fecha_vencimiento'],
        'lote nuevo sin código' => [fn (array $c) => ['lote' => ['fecha_vencimiento' => rn01Dia(120)]] + $c, 'lote.codigo'],
        'lote nuevo con código vacío' => [fn (array $c) => ['lote' => ['codigo' => '', 'fecha_vencimiento' => rn01Dia(120)]] + $c, 'lote.codigo'],
        'fecha_vencimiento inexistente (2026-13-01)' => [fn (array $c) => ['lote' => ['codigo' => 'RN01-REF', 'fecha_vencimiento' => '2026-13-01']] + $c, 'lote.fecha_vencimiento'],
        'fecha_vencimiento 30 de febrero' => [fn (array $c) => ['lote' => ['codigo' => 'RN01-REF', 'fecha_vencimiento' => '2027-02-30']] + $c, 'lote.fecha_vencimiento'],
        'fecha_vencimiento no es fecha' => [fn (array $c) => ['lote' => ['codigo' => 'RN01-REF', 'fecha_vencimiento' => 'mañana']] + $c, 'lote.fecha_vencimiento'],
        'sin lote_id ni lote' => [fn (array $c) => array_diff_key($c, ['lote' => 1]), 'lote_id'],
        'lote_id inexistente' => [fn (array $c) => ['lote_id' => 987654321] + array_diff_key($c, ['lote' => 1]), 'lote_id'],
    ]);

    it('RN-01-AC8 RN-01-AC3: una entrada con un lote_id de OTRO producto es rechazada (422) y no crea existencia cruzada', function () {
        actuarComo('regente_farmacia');
        $otroProducto = Producto::factory()->create();
        $loteAjeno = Lote::factory()->create(['producto_id' => $otroProducto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $loteAjeno->id,
            'cantidad' => 5,
        ]);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('lote_id')
            ->and(rn01Instantanea())->toBe($antes);
    });

    it('RN-01-AC8 RN-01-AC2 S-23: código de lote ya registrado con OTRA fecha → 422 LOTE_NO_COINCIDE con lote_id y ambas fechas; la fecha del lote no cambia y nada se crea', function (int $diasRegistrada, int $diasEnviada, bool $conExistenciaAqui) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-FECHA', 'fecha_vencimiento' => rn01Dia($diasRegistrada)]);
        if ($conExistenciaAqui) {
            rn01Existencia($this->bodega, $lote, 10);
        } else {
            rn01Existencia(Bodega::factory()->create(), $lote, 10);
        }
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-FECHA', 'fecha_vencimiento' => rn01Dia($diasEnviada)],
            'cantidad' => 5,
        ]);

        esperarError($r, 422, 'LOTE_NO_COINCIDE');
        expect($r->json('error.detalles.lote_id'))->toBe($lote->id)
            ->and($r->json('error.detalles.fecha_registrada'))->toBe(rn01Dia($diasRegistrada))
            ->and($r->json('error.detalles.fecha_enviada'))->toBe(rn01Dia($diasEnviada))
            ->and(rn01Instantanea())->toBe($antes)
            ->and($lote->fresh()->fecha_vencimiento->toDateString())->toBe(rn01Dia($diasRegistrada))
            ->and(Lote::query()->where('codigo', 'RN01-FECHA')->count())->toBe(1);
    })->with([
        'registrado +100, enviado +300 (otra fecha futura)' => [100, 300, true],
        'registrado +100, enviado +101 (un día después)' => [100, 101, true],
        'registrado +100, enviado +99 (un día antes)' => [100, 99, true],
        'registrado +100, enviado ayer (fecha enviada vencida → NO_COINCIDE, no LOTE_VENCIDO)' => [100, -1, true],
        'registrado +100, enviado hoy (fecha enviada vencida por S-01 → NO_COINCIDE)' => [100, 0, true],
        'registrado +100, enviado hace un año' => [100, -365, true],
        'registrado ayer (vencido), enviado +100 → NO_COINCIDE (la discrepancia manda)' => [-1, 100, true],
        'lote con existencia solo en otra bodega, enviado +300' => [100, 300, false],
        'lote con existencia solo en otra bodega, enviado ayer' => [100, -1, false],
    ]);

    it('RN-01-AC8 S-23: lote existente vencido nombrado SIN discrepancia → 422 LOTE_VENCIDO con detalles.lote_id del lote existente y su fecha real', function (int $dias, string $forma) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-VENC-DET', 'fecha_vencimiento' => rn01Dia($dias)]);
        rn01Existencia($this->bodega, $lote, 20);
        $antes = rn01Instantanea();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 5];
        $cuerpo += $forma === 'lote_id'
            ? ['lote_id' => $lote->id]
            : ['lote' => ['codigo' => 'RN01-VENC-DET', 'fecha_vencimiento' => rn01Dia($dias)]];

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect($r->json('error.detalles.lote_id'))->toBe($lote->id)
            ->and($r->json('error.detalles.fecha_vencimiento'))->toBe(rn01Dia($dias))
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'vence hoy (S-01)' => [0],
        'venció ayer' => [-1],
        'venció hace un año' => [-365],
    ])->with([
        'por lote_id' => ['lote_id'],
        'por código y misma fecha' => ['codigo'],
    ]);

    it('RN-01-AC8 RN-01-AC2 S-23: el mismo código de lote en OTRO producto no es discrepancia: se crea un lote nuevo para este producto (201)', function () {
        actuarComo('regente_farmacia');
        $otroProducto = Producto::factory()->create();
        $loteAjeno = Lote::factory()->create(['producto_id' => $otroProducto->id, 'codigo' => 'RN01-COMPARTIDO', 'fecha_vencimiento' => rn01Dia(100)]);

        $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-COMPARTIDO', 'fecha_vencimiento' => rn01Dia(300)],
            'cantidad' => 4,
        ])->assertCreated();

        $nuevo = Lote::query()->where(['producto_id' => $this->producto->id, 'codigo' => 'RN01-COMPARTIDO'])->sole();
        expect($nuevo->fecha_vencimiento->toDateString())->toBe(rn01Dia(300))
            ->and($loteAjeno->fresh()->fecha_vencimiento->toDateString())->toBe(rn01Dia(100))
            ->and(Existencia::query()->sole()->lote_id)->toBe($nuevo->id);
    });

    it('RN-01-AC8 S-22: entrada en bodega inactiva o de producto inactivo → 422 VALIDACION con el campo en detalles.campos y sin cambios', function (string $inactivo, string $forma) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-INACT', 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);

        if ($inactivo === 'bodega_id') {
            $this->bodega->forceFill(['activa' => false])->save();
        } else {
            $this->producto->forceFill(['activo' => false])->save();
        }
        $antes = rn01Instantanea();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 5];
        $cuerpo += match ($forma) {
            'lote_id' => ['lote_id' => $lote->id],
            'lote existente' => ['lote' => ['codigo' => 'RN01-INACT', 'fecha_vencimiento' => rn01Dia(120)]],
            'lote nuevo' => ['lote' => ['codigo' => 'RN01-INACT-NUEVO', 'fecha_vencimiento' => rn01Dia(120)]],
        };

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($inactivo)
            ->and(rn01Instantanea())->toBe($antes)
            ->and(Lote::query()->where('codigo', 'RN01-INACT-NUEVO')->exists())->toBeFalse();
    })->with([
        'bodega inactiva' => ['bodega_id'],
        'producto inactivo' => ['producto_id'],
    ])->with([
        'por lote_id' => ['lote_id'],
        'por código de lote existente' => ['lote existente'],
        'con lote nuevo' => ['lote nuevo'],
    ]);

    it('RN-01-AC8 S-24: la cantidad máxima por entrada (1.000.000) se acepta (201) y suma exactamente', function (string $forma) {
        actuarComo('regente_farmacia');
        $kardexAntes = rn01MovimientosKardex();
        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 1_000_000];

        if ($forma === 'lote_id') {
            $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
            rn01Existencia($this->bodega, $lote, 7);
            $cuerpo['lote_id'] = $lote->id;
            $saldoAnterior = 7;
        } else {
            $cuerpo['lote'] = ['codigo' => 'RN01-MILLON', 'fecha_vencimiento' => rn01Dia(120)];
            $saldoAnterior = 0;
        }

        $this->postJson(RN01_URI_ENTRADAS, $cuerpo)->assertCreated();

        $existencia = Existencia::query()->sole();
        expect($existencia->cantidad)->toBe($saldoAnterior + 1_000_000);
        rn01AfirmarKardexEntrada($kardexAntes, $existencia, 1_000_000, $saldoAnterior);
    })->with([
        'sobre existencia previa (lote_id)' => ['lote_id'],
        'lote nuevo' => ['codigo'],
    ]);

    it('RN-01-AC8 S-24: cantidad por encima del máximo o entero escrito como decimal → 422 VALIDACION con detalles.campos.cantidad y sin cambios', function (mixed $cantidad) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        // JSON_PRESERVE_ZERO_FRACTION: sin él json_encode(2.0) envía "2" y no se probaría el decimal.
        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $lote->id,
            'cantidad' => $cantidad,
        ], [], JSON_PRESERVE_ZERO_FRACTION);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('cantidad')
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'máximo + 1 (1.000.001)' => [1_000_001],
        'entero como decimal 2.0' => [2.0],
        'máximo como decimal 1000000.0' => [1_000_000.0],
        'máximo + 0.5' => [1_000_000.5],
        'booleano false' => [false],
        'PHP_INT_MAX' => [PHP_INT_MAX],
    ]);

    it('RN-01-AC8: si saldo + cantidad supera 2.147.483.647 → 422 CANTIDAD_EXCEDE_MAXIMO con saldo_actual, cantidad y maximo, sin cambios', function (int $saldo, int $cantidad, string $forma) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-TOPE', 'fecha_vencimiento' => rn01Dia(120)]);
        $existencia = rn01Existencia($this->bodega, $lote, 1);
        $existencia->forceFill(['cantidad' => $saldo])->save();
        $antes = rn01Instantanea();

        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => $cantidad];
        $cuerpo += $forma === 'lote_id'
            ? ['lote_id' => $lote->id]
            : ['lote' => ['codigo' => 'RN01-TOPE', 'fecha_vencimiento' => rn01Dia(120)]];

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);

        esperarError($r, 422, 'CANTIDAD_EXCEDE_MAXIMO');
        expect($r->json('error.detalles.saldo_actual'))->toBe($saldo)
            ->and($r->json('error.detalles.cantidad'))->toBe($cantidad)
            ->and($r->json('error.detalles.maximo'))->toBe(2_147_483_647)
            ->and(rn01Instantanea())->toBe($antes)
            ->and($existencia->fresh()->cantidad)->toBe($saldo);
    })->with([
        'saldo máx − 10, entra 11 (máx + 1)' => [2_147_483_637, 11],
        'saldo en el máximo, entra 1' => [2_147_483_647, 1],
        'saldo máx − 999.999, entra 1.000.000 (máx + 1)' => [2_147_483_648 - 1_000_000, 1_000_000],
        'saldo en el máximo, entra 1.000.000' => [2_147_483_647, 1_000_000],
    ])->with([
        'por lote_id' => ['lote_id'],
        'por código y fecha' => ['codigo'],
    ]);

    it('RN-01-AC8 frontera de desbordamiento: saldo + cantidad = 2.147.483.647 exacto se acepta (201)', function () {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        $existencia = rn01Existencia($this->bodega, $lote, 1);
        $existencia->forceFill(['cantidad' => 2_147_483_637])->save();
        $kardexAntes = rn01MovimientosKardex();

        $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $lote->id,
            'cantidad' => 10,
        ])->assertCreated();

        expect($existencia->fresh()->cantidad)->toBe(2_147_483_647);
        rn01AfirmarKardexEntrada($kardexAntes, $existencia, 10, 2_147_483_637);
    });

    it('RN-01-AC8 RN-01-AC9: la respuesta 201 incluye bodega{id,codigo,nombre} y producto{id,codigo,nombre} y coincide con la fila de GET /inventario', function (string $forma) {
        actuarComo('regente_farmacia');
        $cuerpo = ['bodega_id' => $this->bodega->id, 'producto_id' => $this->producto->id, 'cantidad' => 9];

        if ($forma === 'lote_id') {
            $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'codigo' => 'RN01-RESP', 'fecha_vencimiento' => rn01Dia(45)]);
            rn01Existencia($this->bodega, $lote, 3);
            $cuerpo['lote_id'] = $lote->id;
        } else {
            $cuerpo['lote'] = ['codigo' => 'RN01-RESP', 'fecha_vencimiento' => rn01Dia(45)];
        }

        $r = $this->postJson(RN01_URI_ENTRADAS, $cuerpo);
        $r->assertCreated();
        $creada = cuerpoDatos($r);

        expect($creada['bodega'] ?? null)->toBe([
            'id' => $this->bodega->id,
            'codigo' => $this->bodega->codigo,
            'nombre' => $this->bodega->nombre,
        ])->and($creada['producto'] ?? null)->toBe([
            'id' => $this->producto->id,
            'codigo' => $this->producto->codigo,
            'nombre' => $this->producto->nombre,
        ])->and($creada['lote']['codigo'] ?? null)->toBe('RN01-RESP')
            ->and($creada['fecha_vencimiento'] ?? null)->toBe(rn01Dia(45))
            ->and($creada['cantidad'] ?? null)->toBe($forma === 'lote_id' ? 12 : 9)
            ->and($creada['vencido'] ?? null)->toBeFalse();

        $listado = $this->getJson(RN01_URI_INVENTARIO.'?bodega_id='.$this->bodega->id);
        $listado->assertOk();
        $fila = $listado->json('data.0');
        expect($listado->json('data'))->toHaveCount(1);

        // Todo campo de la fila del listado aparece igual en la respuesta de la entrada.
        foreach ($fila as $campo => $valor) {
            expect($creada)->toHaveKey($campo)
                ->and($creada[$campo])->toBe($valor, "campo {$campo}");
        }
    })->with([
        'lote existente por lote_id' => ['lote_id'],
        'lote nuevo' => ['codigo'],
    ]);

    it('RN-01-AC8: lote.codigo con caracteres de control → 422 VALIDACION con detalles.campos["lote.codigo"] (nunca 500) y sin cambios', function (string $codigo) {
        actuarComo('regente_farmacia');
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => $codigo, 'fecha_vencimiento' => rn01Dia(120)],
            'cantidad' => 5,
        ]);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('lote.codigo')
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'byte nulo en medio' => ["LOTE\u{0000}01"],
        'solo byte nulo' => ["\u{0000}"],
        'salto de línea en medio' => ["LOTE\n01"],
        'retorno de carro en medio' => ["LOTE\r01"],
        'tabulador en medio' => ["LOTE\t01"],
        'escape ESC' => ["LOTE\u{001B}01"],
        'DEL (0x7F)' => ["LOTE\u{007F}01"],
        'control C1 (U+0085)' => ["LOTE\u{0085}01"],
        'formato: espacio de ancho cero (U+200B)' => ["L0\u{200B}01"],
        'formato: guion suave (U+00AD)' => ["L0\u{00AD}01"],
        'formato: unión de ancho cero (U+200D)' => ["L0\u{200D}01"],
        'formato: BOM / ZWNBSP (U+FEFF) en medio' => ["L0\u{FEFF}01"],
        'formato: unión de palabras (U+2060)' => ["L00\u{2060}1"],
    ]);

    it('RN-01-AC8: lote.fecha_vencimiento con año de 5 dígitos o ≥ 2100 → 422 VALIDACION (nunca 500) y sin cambios', function (string $fecha) {
        actuarComo('regente_farmacia');
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-ANIO', 'fecha_vencimiento' => $fecha],
            'cantidad' => 5,
        ]);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('lote.fecha_vencimiento')
            ->and(rn01Instantanea())->toBe($antes);
    })->with([
        'año 2100 (primer día)' => ['2100-01-01'],
        'año 9999' => ['9999-12-31'],
        'año de 5 dígitos 10000' => ['10000-01-01'],
        'año de 5 dígitos 99999' => ['99999-12-31'],
        'año de 6 dígitos con signo' => ['+275760-09-13'],
    ]);

    it('RN-01-AC8 frontera: fecha_vencimiento 2099-12-31 (último día antes de 2100) se acepta (201)', function () {
        actuarComo('regente_farmacia');

        $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote' => ['codigo' => 'RN01-2099', 'fecha_vencimiento' => '2099-12-31'],
            'cantidad' => 5,
        ])->assertCreated();

        expect(Lote::query()->where('codigo', 'RN01-2099')->sole()->fecha_vencimiento->toDateString())->toBe('2099-12-31')
            ->and(Existencia::query()->sole()->cantidad)->toBe(5);
    });

    it('RN-01-AC8 roles: solo el regente registra entradas; el resto → 403 NO_AUTORIZADO sin cambios', function (string $rol) {
        actuarComo($rol);
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $lote->id,
            'cantidad' => 5,
        ]);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn01Instantanea())->toBe($antes);
    })->with(['auxiliar_farmacia', 'medico', 'auditor', 'admin']);

    it('RN-01-AC8 roles: sin token → 401 NO_AUTENTICADO sin cambios', function () {
        $lote = Lote::factory()->create(['producto_id' => $this->producto->id, 'fecha_vencimiento' => rn01Dia(120)]);
        rn01Existencia($this->bodega, $lote, 10);
        $antes = rn01Instantanea();

        $r = $this->postJson(RN01_URI_ENTRADAS, [
            'bodega_id' => $this->bodega->id,
            'producto_id' => $this->producto->id,
            'lote_id' => $lote->id,
            'cantidad' => 5,
        ]);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn01Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| AC9: GET /api/v1/inventario con filtros combinables
|--------------------------------------------------------------------------
*/

describe('RN-01-AC9 consulta de inventario', function () {
    it('RN-01-AC9: cada existencia trae lote.codigo, fecha_vencimiento, cantidad y vencido (bool); vence hoy → vencido=true', function () {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN01_URI_INVENTARIO);

        $r->assertOk();
        $filas = collect($r->json('data'));
        expect($filas)->toHaveCount(6);

        foreach ($filas as $fila) {
            expect($fila)->toHaveKeys(['lote', 'fecha_vencimiento', 'cantidad', 'vencido'])
                ->and($fila['lote'])->toHaveKey('codigo')
                ->and($fila['lote']['codigo'])->toBeString()
                ->and($fila['cantidad'])->toBeInt()
                ->and($fila['vencido'])->toBeBool()
                ->and($fila['fecha_vencimiento'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        }

        $porLote = $filas->groupBy(fn (array $f) => $f['lote']['codigo']);
        $esperado = [
            'RN01-L1' => [rn01Dia(200), false],
            'RN01-L2' => [rn01Dia(0), true],   // vence hoy (S-01)
            'RN01-L3' => [rn01Dia(-1), true],  // venció ayer
            'RN01-L4' => [rn01Dia(1), false],  // vence mañana
        ];

        foreach ($esperado as $codigo => [$fecha, $vencido]) {
            expect($porLote->has($codigo))->toBeTrue("falta el lote {$codigo}");
            foreach ($porLote[$codigo] as $fila) {
                expect($fila['fecha_vencimiento'])->toBe($fecha, "fecha de {$codigo}")
                    ->and($fila['vencido'])->toBe($vencido, "vencido de {$codigo}");
            }
        }
    });

    it('RN-01-AC9: los filtros bodega_id, producto_id y lote_id son combinables y devuelven exactamente las existencias que cumplen todos', function (array $filtros, array $esperadas) {
        $e = rn01EscenarioInventario();
        actuarComo('auxiliar_farmacia');

        $query = [];
        foreach ($filtros as $campo => $nombre) {
            $query[$campo] = match ($campo) {
                'bodega_id' => $e['bodegas'][$nombre]->id,
                'producto_id' => $e['productos'][$nombre]->id,
                'lote_id' => $e['lotes'][$nombre]->id,
            };
        }

        $r = $this->getJson(RN01_URI_INVENTARIO.'?'.http_build_query($query));

        $r->assertOk();
        sort($esperadas);
        expect(rn01ClavesInventario($r))->toBe($esperadas);
    })->with([
        'sin filtros' => [[], ['RN01-L1|11', 'RN01-L2|12', 'RN01-L3|13', 'RN01-L1|14', 'RN01-L4|15', 'RN01-L2|16']],
        'bodega B1' => [['bodega_id' => 'B1'], ['RN01-L1|11', 'RN01-L2|12', 'RN01-L3|13']],
        'producto P1' => [['producto_id' => 'P1'], ['RN01-L1|11', 'RN01-L2|12', 'RN01-L1|14', 'RN01-L2|16']],
        'lote L1' => [['lote_id' => 'L1'], ['RN01-L1|11', 'RN01-L1|14']],
        'bodega B2 + producto P1' => [['bodega_id' => 'B2', 'producto_id' => 'P1'], ['RN01-L1|14', 'RN01-L2|16']],
        'bodega B1 + lote L2' => [['bodega_id' => 'B1', 'lote_id' => 'L2'], ['RN01-L2|12']],
        'producto P2 + lote L4' => [['producto_id' => 'P2', 'lote_id' => 'L4'], ['RN01-L4|15']],
        'bodega B2 + producto P2 + lote L4' => [['bodega_id' => 'B2', 'producto_id' => 'P2', 'lote_id' => 'L4'], ['RN01-L4|15']],
        'producto P2 + lote L1 (lote de otro producto) → vacío' => [['producto_id' => 'P2', 'lote_id' => 'L1'], []],
        'bodega B1 + lote L4 (sin existencia ahí) → vacío' => [['bodega_id' => 'B1', 'lote_id' => 'L4'], []],
        'los lotes vencidos se listan (no se filtran)' => [['bodega_id' => 'B1', 'producto_id' => 'P2'], ['RN01-L3|13']],
    ]);

    it('RN-01-AC9 zona horaria: a las 23:30 de Bogotá un lote que vence "mañana" (hoy en UTC) sale vencido=false', function () {
        $this->travelTo(Carbon::parse('2026-11-05 04:30:00', 'UTC')); // = 2026-11-04 23:30 Bogotá
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn01Existencia($bodega, Lote::factory()->create(['producto_id' => $producto->id, 'codigo' => 'RN01-TZ-04', 'fecha_vencimiento' => '2026-11-04']), 1);
        rn01Existencia($bodega, Lote::factory()->create(['producto_id' => $producto->id, 'codigo' => 'RN01-TZ-05', 'fecha_vencimiento' => '2026-11-05']), 2);
        actuarComo('auditor');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?bodega_id='.$bodega->id);

        $r->assertOk();
        $vencidoPorCodigo = collect($r->json('data'))->mapWithKeys(fn (array $f) => [$f['lote']['codigo'] => $f['vencido']])->all();
        ksort($vencidoPorCodigo);
        expect($vencidoPorCodigo)->toBe(['RN01-TZ-04' => true, 'RN01-TZ-05' => false]);
    });

    it('RN-01-AC9: la consulta no modifica el inventario', function () {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');
        $antes = rn01Instantanea();

        $this->getJson(RN01_URI_INVENTARIO)->assertOk();

        expect(rn01Instantanea())->toBe($antes);
    });

    it('RN-01-AC9: filtros con formato inválido → 422 VALIDACION (nunca 500)', function (string $campo, string $valor) {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?'.http_build_query([$campo => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo);
    })->with([
        'bodega_id texto' => ['bodega_id', 'abc'],
        'producto_id decimal' => ['producto_id', '1.5'],
        'lote_id texto' => ['lote_id', 'L1'],
        'bodega_id fuera de rango bigint' => ['bodega_id', '99999999999999999999'],
    ]);

    it('RN-01-AC9 S-25: las existencias con cantidad 0 se listan (con cantidad 0 y su vencido), con y sin filtro', function (bool $filtrarBodega) {
        $e = rn01EscenarioInventario();
        $bodega = $e['bodegas']['B1'];
        $vigente = Lote::factory()->create(['producto_id' => $e['productos']['P2']->id, 'codigo' => 'RN01-CERO-VIG', 'fecha_vencimiento' => rn01Dia(50)]);
        $vencido = Lote::factory()->create(['producto_id' => $e['productos']['P2']->id, 'codigo' => 'RN01-CERO-VENC', 'fecha_vencimiento' => rn01Dia(-3)]);
        rn01Existencia($bodega, $vigente, 0);
        rn01Existencia($bodega, $vencido, 0);
        actuarComo('auxiliar_farmacia');

        $query = $filtrarBodega ? '?bodega_id='.$bodega->id : '';
        $r = $this->getJson(RN01_URI_INVENTARIO.$query);

        $r->assertOk();
        $porCodigo = collect($r->json('data'))->keyBy(fn (array $f) => $f['lote']['codigo']);
        expect($porCodigo->has('RN01-CERO-VIG'))->toBeTrue()
            ->and($porCodigo->has('RN01-CERO-VENC'))->toBeTrue()
            ->and($porCodigo['RN01-CERO-VIG']['cantidad'])->toBe(0)
            ->and($porCodigo['RN01-CERO-VIG']['vencido'])->toBeFalse()
            ->and($porCodigo['RN01-CERO-VENC']['cantidad'])->toBe(0)
            ->and($porCodigo['RN01-CERO-VENC']['vencido'])->toBeTrue();
    })->with([
        'sin filtros' => [false],
        'filtrando por bodega' => [true],
    ]);

    it('RN-01-AC9 S-25: pagina 25 por defecto; las páginas no se solapan y cubren todas las existencias', function () {
        rn01CongelarHoy();
        $codigos = rn01ExistenciasMasivas(30);
        actuarComo('regente_farmacia');

        $p1 = $this->getJson(RN01_URI_INVENTARIO);
        $p2 = $this->getJson(RN01_URI_INVENTARIO.'?page=2');
        $p3 = $this->getJson(RN01_URI_INVENTARIO.'?page=3');

        $p1->assertOk();
        $p2->assertOk();
        $p3->assertOk();
        expect($p1->json('data'))->toHaveCount(25)
            ->and($p2->json('data'))->toHaveCount(5)
            ->and($p3->json('data'))->toBe([])
            ->and($p1->json('meta.per_page'))->toBe(25)
            ->and($p1->json('meta.total'))->toBe(30);

        $vistos = [...rn01CodigosPagina($p1), ...rn01CodigosPagina($p2)];
        sort($vistos);
        expect($vistos)->toBe($codigos);
    });

    it('RN-01-AC9 S-25: por_pagina admite hasta 100 (devuelve 100 de 105)', function (int $porPagina, int $esperadas) {
        rn01CongelarHoy();
        rn01ExistenciasMasivas(105);
        actuarComo('auditor');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?por_pagina='.$porPagina);

        $r->assertOk();
        expect($r->json('data'))->toHaveCount($esperadas)
            ->and($r->json('meta.per_page'))->toBe($porPagina)
            ->and($r->json('meta.total'))->toBe(105);
    })->with([
        'mínimo 1' => [1, 1],
        'máximo 100' => [100, 100],
        '99' => [99, 99],
    ]);

    it('RN-01-AC9 S-25: por_pagina fuera de rango o mal formado → 422 VALIDACION con detalles.campos.por_pagina (nunca 500)', function (string $valor) {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?'.http_build_query(['por_pagina' => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('por_pagina');
    })->with([
        'máximo + 1 (101)' => ['101'],
        'cero' => ['0'],
        'negativo' => ['-1'],
        'decimal' => ['2.5'],
        'texto' => ['muchos'],
        'desmesurado' => ['99999999999999999999'],
    ]);

    it('RN-01-AC9 S-25: un filtro con id bien formado pero inexistente → 200 con data vacío', function (string $campo) {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?'.http_build_query([$campo => 987654321]));

        $r->assertOk();
        expect($r->json('data'))->toBe([]);
    })->with(['bodega_id', 'producto_id', 'lote_id']);

    it('RN-01-AC9 S-25: page desmesurado, cero, negativo o no entero → 422 VALIDACION con detalles.campos.page (nunca 500)', function (string $valor) {
        rn01EscenarioInventario();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN01_URI_INVENTARIO.'?'.http_build_query(['page' => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('page');
    })->with([
        'desmesurado 99999999999' => ['99999999999'],
        'PHP_INT_MAX' => [(string) PHP_INT_MAX],
        'mayor que bigint' => ['99999999999999999999'],
        'cero' => ['0'],
        'negativo' => ['-1'],
        'decimal' => ['1.5'],
        'texto' => ['dos'],
    ]);

    it('RN-01-AC9 roles: auxiliar, regente y auditor consultan (200); médico y admin → 403 NO_AUTORIZADO', function (string $rol, bool $permitido) {
        rn01EscenarioInventario();
        actuarComo($rol);

        $r = $this->getJson(RN01_URI_INVENTARIO);

        if ($permitido) {
            $r->assertOk();
            expect($r->json('data'))->toHaveCount(6);
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
        }
    })->with([
        'auxiliar_farmacia' => ['auxiliar_farmacia', true],
        'regente_farmacia' => ['regente_farmacia', true],
        'auditor' => ['auditor', true],
        'medico' => ['medico', false],
        'admin (S-13)' => ['admin', false],
    ]);

    it('RN-01-AC9 roles: sin token → 401 NO_AUTENTICADO', function () {
        rn01EscenarioInventario();

        esperarError($this->getJson(RN01_URI_INVENTARIO), 401, 'NO_AUTENTICADO');
    });
});
