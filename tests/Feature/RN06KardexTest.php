<?php

declare(strict_types=1);

use App\Exceptions\Dominio\KardexInmutableException;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * RN-06 — Kardex completo e inmutable.
 *
 * Alcance: AC1 (ENTRADA y AJUSTE; SALIDA_* y ENTRADA_TRASLADO se prueban con RN-02/RN-07),
 * AC2–AC8. La concurrencia (ajustes y entradas simultáneos) está en
 * tests/Feature/SinTransaccion/RN06KardexConcurrenteTest.php; la semilla (AC2 tras
 * migrate:fresh --seed) y "registrar exige transacción" en tests/Feature/SinTransaccion/.
 *
 * Contrato (supuestos S-32..S-40 del índice de la spec):
 *  - POST /api/v1/inventario/ajustes {existencia_id, cantidad (entero ≠ 0 con signo, |cantidad| ≤ 1.000.000, S-32),
 *    motivo (10–500 chars tras trim, sin Cc/Cf/Zl/Zp, S-33)}; se admite sobre lotes vencidos e inactivos (S-34).
 *  - GET /api/v1/kardex: filtros producto_id, lote_id, bodega_id, existencia_id, desde, hasta (fechas Y-m-d,
 *    inclusivas, día de Bogotá, S-36); paginado como S-25 (25 por defecto, por_pagina ≤ 100, page); orden
 *    created_at, id ASC. Roles: auxiliar, regente y auditor (S-37).
 *  - Ajuste que dejaría stock negativo → 409 STOCK_INSUFICIENTE (RN-03-AC4, S-32).
 *  - Trigger de inmutabilidad: SQLSTATE 23001 (restrict_violation) o P0001 (RAISE por defecto).
 */

const RN06_URI_AJUSTES = '/api/v1/inventario/ajustes';
const RN06_URI_ENTRADAS = '/api/v1/inventario/entradas';
const RN06_URI_KARDEX = '/api/v1/kardex';
const RN06_HOY = '2026-11-04';
const RN06_SQLSTATE_TRIGGER = ['23001', 'P0001'];
const RN06_MOTIVO = 'Conteo físico de cierre de mes';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn06)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en una hora local de Bogotá. */
function rn06Congelar(string $fechaHoraBogota = RN06_HOY.' 10:00:00'): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Ejecuta SQL que debe fallar dentro de un savepoint y devuelve el SQLSTATE (null si no falló). */
function rn06Sqlstate(Closure $operacion): ?string
{
    try {
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/** Lote vigente (vence dentro de 400 días respecto al reloj congelado). */
function rn06LoteVigente(?Producto $producto = null, ?string $codigo = null): Lote
{
    $atributos = ['fecha_vencimiento' => Carbon::parse(RN06_HOY, 'America/Bogota')->addDays(400)->toDateString()];
    if ($producto !== null) {
        $atributos['producto_id'] = $producto->id;
    }
    if ($codigo !== null) {
        $atributos['codigo'] = $codigo;
    }

    return Lote::factory()->create($atributos);
}

/** Regente "de servicio" para preparar stock por la API sin afectar al usuario que prueba. */
function rn06Regente(): User
{
    static $cache = [];
    $clave = spl_object_id(test());

    if (! isset($cache[$clave]) || ! User::query()->whereKey($cache[$clave]->id)->exists()) {
        $cache[$clave] = usuarioConRol('regente_farmacia');
    }

    return $cache[$clave];
}

/** POST /inventario/entradas con un lote ya registrado, autenticado como $usuario (o el regente de servicio). */
function rn06Entrada(Bodega $bodega, Lote $lote, mixed $cantidad, ?User $usuario = null): TestResponse
{
    Sanctum::actingAs($usuario ?? rn06Regente());

    return test()->postJson(RN06_URI_ENTRADAS, [
        'bodega_id' => $bodega->id,
        'producto_id' => $lote->producto_id,
        'lote_id' => $lote->id,
        'cantidad' => $cantidad,
    ]);
}

/** POST /inventario/ajustes autenticado como $usuario (o el regente de servicio). @param array<string, mixed> $extra */
function rn06Ajuste(int $existenciaId, mixed $cantidad, mixed $motivo = RN06_MOTIVO, ?User $usuario = null, array $extra = []): TestResponse
{
    Sanctum::actingAs($usuario ?? rn06Regente());

    return test()->postJson(RN06_URI_AJUSTES, array_merge([
        'existencia_id' => $existenciaId,
        'cantidad' => $cantidad,
        'motivo' => $motivo,
    ], $extra));
}

/**
 * Existencia con stock creado por una ENTRADA real (así su kardex parte de 0 y cuadra).
 */
function rn06ExistenciaConStock(int $cantidad, ?Bodega $bodega = null, ?Lote $lote = null): Existencia
{
    $bodega ??= Bodega::factory()->create();
    $lote ??= rn06LoteVigente();

    rn06Entrada($bodega, $lote, $cantidad)->assertCreated();

    return Existencia::query()
        ->where(['bodega_id' => $bodega->id, 'producto_id' => $lote->producto_id, 'lote_id' => $lote->id])
        ->sole();
}

/** Movimientos (filas crudas) de una existencia o de todas, en orden created_at, id. @return list<object> */
function rn06Movimientos(?int $existenciaId = null): array
{
    return DB::table('movimientos_kardex')
        ->when($existenciaId !== null, fn ($q) => $q->where('existencia_id', $existenciaId))
        ->orderBy('created_at')->orderBy('id')
        ->get()->all();
}

function rn06TotalMovimientos(): int
{
    return DB::table('movimientos_kardex')->count();
}

/** Stock actual de una existencia leído de la BD. */
function rn06Stock(int $existenciaId): int
{
    return (int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad');
}

/**
 * RN-06-AC2: la cadena de saldos de la existencia cuadra.
 *  - el primer movimiento parte de $saldoInicial (0 si la existencia nació con una entrada);
 *  - cada saldo_posterior = saldo_anterior + cantidad;
 *  - cada saldo_anterior = saldo_posterior del movimiento anterior (sin huecos ni duplicados);
 *  - SUM(cantidad) = existencias.cantidad − $saldoInicial y el último saldo_posterior = existencias.cantidad.
 */
function rn06AfirmarCadena(int $existenciaId, int $saldoInicial = 0): void
{
    $movimientos = rn06Movimientos($existenciaId);
    $stock = rn06Stock($existenciaId);

    expect($movimientos)->not->toBeEmpty("la existencia {$existenciaId} no tiene kardex");

    $saldo = $saldoInicial;
    foreach ($movimientos as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "movimiento #{$i} (id {$m->id}): saldo_anterior no encadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad, "movimiento #{$i} (id {$m->id}): saldo_posterior ≠ anterior + cantidad")
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }

    $suma = array_sum(array_map(fn (object $m): int => (int) $m->cantidad, $movimientos));
    expect($suma + $saldoInicial)->toBe($stock, 'SUM(cantidad) no coincide con existencias.cantidad')
        ->and($saldo)->toBe($stock, 'el último saldo_posterior no coincide con existencias.cantidad');
}

/**
 * Estado completo de inventario y kardex: en un rechazo debe quedar idéntico.
 *
 * @return array<string, mixed>
 */
function rn06Instantanea(): array
{
    return [
        'lotes' => DB::table('lotes')->orderBy('id')->get(['id', 'producto_id', 'codigo', 'fecha_vencimiento'])
            ->map(fn (object $f): array => (array) $f)->all(),
        'existencias' => DB::table('existencias')->orderBy('id')->get(['id', 'bodega_id', 'producto_id', 'lote_id', 'cantidad'])
            ->map(fn (object $f): array => (array) $f)->all(),
        'kardex' => DB::table('movimientos_kardex')->orderBy('id')->get()
            ->map(fn (object $f): array => (array) $f)->all(),
    ];
}

/**
 * Fila cruda válida (AJUSTE +5 de 0 a 5) para INSERT directo, con sobrescrituras.
 *
 * @param  array<string, mixed>  $sobrescribir
 * @return array<string, mixed>
 */
function rn06FilaMovimiento(Existencia $existencia, User $usuario, array $sobrescribir = []): array
{
    return array_merge([
        'tipo' => 'AJUSTE',
        'existencia_id' => $existencia->id,
        'bodega_id' => $existencia->bodega_id,
        'producto_id' => $existencia->producto_id,
        'lote_id' => $existencia->lote_id,
        'cantidad' => 5,
        'saldo_anterior' => 0,
        'saldo_posterior' => 5,
        'motivo' => RN06_MOTIVO,
        'usuario_id' => $usuario->id,
        'documento_tipo' => null,
        'documento_id' => null,
        'created_at' => now(),
    ], $sobrescribir);
}

/** Envía el log de la aplicación a un archivo temporal propio del test y devuelve su ruta. */
function rn06LogDeTest(): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'farma-rn06-log-');
    config([
        'logging.channels.rn06_prueba' => ['driver' => 'single', 'path' => $ruta, 'level' => 'debug'],
        'logging.default' => 'rn06_prueba',
    ]);

    return $ruta;
}

/** Ids de movimientos de una respuesta paginada, en el orden recibido. @return list<int> */
function rn06IdsRespuesta(TestResponse $respuesta): array
{
    $data = $respuesta->json('data');
    expect($data)->toBeArray();

    return array_map(fn (array $f): int => (int) $f['id'], $data);
}

/*
|--------------------------------------------------------------------------
| AC1: cada operación crea un movimiento con todos sus campos
|--------------------------------------------------------------------------
*/

describe('RN-06-AC1 campos del movimiento', function () {
    beforeEach(fn () => rn06Congelar());

    it('RN-06-AC1: una entrada de inventario crea un movimiento ENTRADA con cantidad +, saldos, usuario, existencia y created_at', function () {
        $regente = usuarioConRol('regente_farmacia');
        $bodega = Bodega::factory()->create();
        $lote = rn06LoteVigente();

        rn06Entrada($bodega, $lote, 40, $regente)->assertCreated();

        $existencia = Existencia::query()->where(['bodega_id' => $bodega->id, 'lote_id' => $lote->id])->sole();
        $m = DB::table('movimientos_kardex')->sole();

        expect($m->tipo)->toBe('ENTRADA')
            ->and((int) $m->cantidad)->toBe(40)
            ->and((int) $m->saldo_anterior)->toBe(0)
            ->and((int) $m->saldo_posterior)->toBe(40)
            ->and((int) $m->usuario_id)->toBe($regente->id)
            ->and((int) $m->existencia_id)->toBe($existencia->id)
            ->and((int) $m->bodega_id)->toBe($bodega->id)
            ->and((int) $m->producto_id)->toBe($lote->producto_id)
            ->and((int) $m->lote_id)->toBe($lote->id)
            ->and(Carbon::parse($m->created_at, config('app.timezone'))->equalTo(now()))->toBeTrue("created_at {$m->created_at} ≠ ahora")
            ->and($existencia->cantidad)->toBe(40);
    });

    it('RN-06-AC1: una segunda entrada sobre la misma existencia encadena saldo_anterior = saldo previo', function () {
        $bodega = Bodega::factory()->create();
        $lote = rn06LoteVigente();
        rn06Entrada($bodega, $lote, 12)->assertCreated();

        rn06Congelar(RN06_HOY.' 11:30:00');
        rn06Entrada($bodega, $lote, 8)->assertCreated();

        $movimientos = rn06Movimientos();
        expect($movimientos)->toHaveCount(2)
            ->and($movimientos[1]->tipo)->toBe('ENTRADA')
            ->and((int) $movimientos[1]->cantidad)->toBe(8)
            ->and((int) $movimientos[1]->saldo_anterior)->toBe(12)
            ->and((int) $movimientos[1]->saldo_posterior)->toBe(20);
    });

    it('RN-06-AC1 RN-06-AC6: un ajuste crea un movimiento AJUSTE con cantidad con signo, saldos, motivo, usuario y created_at', function (int $inicial, int $cantidad) {
        $existencia = rn06ExistenciaConStock($inicial);
        $regente = usuarioConRol('regente_farmacia');
        rn06Congelar(RN06_HOY.' 15:45:10');

        $r = rn06Ajuste($existencia->id, $cantidad, 'Rotura de 2 frascos en estantería', $regente);

        $r->assertCreated();
        $movimientos = rn06Movimientos($existencia->id);
        expect($movimientos)->toHaveCount(2);
        $m = $movimientos[1];

        expect($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->cantidad)->toBe($cantidad)
            ->and((int) $m->saldo_anterior)->toBe($inicial)
            ->and((int) $m->saldo_posterior)->toBe($inicial + $cantidad)
            ->and($m->motivo)->toBe('Rotura de 2 frascos en estantería')
            ->and((int) $m->usuario_id)->toBe($regente->id)
            ->and((int) $m->existencia_id)->toBe($existencia->id)
            ->and((int) $m->bodega_id)->toBe($existencia->bodega_id)
            ->and((int) $m->producto_id)->toBe($existencia->producto_id)
            ->and((int) $m->lote_id)->toBe($existencia->lote_id)
            ->and(Carbon::parse($m->created_at, config('app.timezone'))->equalTo(now()))->toBeTrue("created_at {$m->created_at} ≠ ahora")
            ->and(rn06Stock($existencia->id))->toBe($inicial + $cantidad);
    })->with([
        'positivo' => [10, 5],
        'negativo' => [10, -4],
    ]);

    it('RN-06-AC1: el tipo solo admite ENTRADA, SALIDA_DISPENSACION, SALIDA_TRASLADO, ENTRADA_TRASLADO y AJUSTE (otro → 23514)', function (string $tipo) {
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $usuario = usuarioConRol('regente_farmacia');

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($existencia, $usuario, ['tipo' => $tipo])
        ));

        expect($sqlstate)->toBe('23514')
            ->and(rn06TotalMovimientos())->toBe(0);
    })->with([
        'SALIDA' => ['SALIDA'],
        'minúsculas ajuste' => ['ajuste'],
        'ENTRADA con espacio' => ['ENTRADA '],
        'vacío' => [''],
        'CORRECCION' => ['CORRECCION'],
    ]);

    it('RN-06-AC1: usuario_id, tipo, cantidad, saldos, existencia y created_at son obligatorios (NOT NULL 23502)', function (string $columna) {
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $usuario = usuarioConRol('regente_farmacia');

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($existencia, $usuario, [$columna => null])
        ));

        expect($sqlstate)->toBe('23502')
            ->and(rn06TotalMovimientos())->toBe(0);
    })->with(['usuario_id', 'tipo', 'cantidad', 'saldo_anterior', 'saldo_posterior', 'existencia_id', 'created_at']);

    it('RN-06-AC1 S-35: una salida (dispensación o traslado) o entrada por traslado sin documento origen completo es rechazada por la BD (23514)', function (string $tipo, int $cantidad, ?string $documentoTipo, ?int $documentoId) {
        $existencia = Existencia::factory()->create(['cantidad' => 10]);
        $usuario = usuarioConRol('regente_farmacia');

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($existencia, $usuario, [
                'tipo' => $tipo, 'cantidad' => $cantidad, 'saldo_anterior' => 10,
                'saldo_posterior' => 10 + $cantidad, 'motivo' => null,
                'documento_tipo' => $documentoTipo, 'documento_id' => $documentoId,
            ])
        ));

        expect($sqlstate)->toBe('23514')
            ->and(rn06TotalMovimientos())->toBe(0);
    })->with([
        'SALIDA_DISPENSACION sin documento' => ['SALIDA_DISPENSACION', -2, null, null],
        'SALIDA_TRASLADO sin documento' => ['SALIDA_TRASLADO', -2, null, null],
        'ENTRADA_TRASLADO sin documento' => ['ENTRADA_TRASLADO', 2, null, null],
        // Un CHECK cuyo resultado es NULL se acepta: documento_tipo NULL no debe colarse.
        'SALIDA_DISPENSACION con documento_tipo NULL e id 7' => ['SALIDA_DISPENSACION', -2, null, 7],
        'SALIDA_TRASLADO con documento_tipo NULL e id 7' => ['SALIDA_TRASLADO', -2, null, 7],
        'ENTRADA_TRASLADO con documento_tipo NULL e id 7' => ['ENTRADA_TRASLADO', 2, null, 7],
        'SALIDA_TRASLADO con documento traslado sin id' => ['SALIDA_TRASLADO', -2, 'traslado', null],
        'SALIDA_DISPENSACION con documento de traslado' => ['SALIDA_DISPENSACION', -2, 'traslado', 7],
    ]);

    it('RN-06-AC8 integridad: bodega/producto/lote del movimiento deben ser los de su existencia (si no, la BD lo rechaza)', function (string $columna) {
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $otra = Existencia::factory()->create(['cantidad' => 0]);
        $usuario = usuarioConRol('regente_farmacia');

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($existencia, $usuario, [$columna => $otra->{$columna}])
        ));

        expect($sqlstate)->toBe('23503')
            ->and(rn06TotalMovimientos())->toBe(0);
    })->with(['bodega_id', 'producto_id', 'lote_id']);
});

/*
|--------------------------------------------------------------------------
| AC2: SUM(cantidad) = existencias.cantidad y saldos encadenados
|--------------------------------------------------------------------------
*/

describe('RN-06-AC2 cuadre del kardex con la existencia', function () {
    beforeEach(fn () => rn06Congelar());

    it('RN-06-AC2: una secuencia mixta de entradas y ajustes (+/−) sobre dos existencias deja cada kardex cuadrado y encadenado', function (array $pasos) {
        $bodega = Bodega::factory()->create();
        $lotes = ['X' => rn06LoteVigente(), 'Y' => rn06LoteVigente()];
        $esperado = ['X' => 0, 'Y' => 0];
        $existencias = [];
        $minuto = 0;

        foreach ($pasos as [$quien, $operacion, $cantidad]) {
            rn06Congelar(Carbon::parse(RN06_HOY.' 08:00:00', 'America/Bogota')->addMinutes(++$minuto)->format('Y-m-d H:i:s'));

            if ($operacion === 'E') {
                rn06Entrada($bodega, $lotes[$quien], $cantidad)->assertCreated();
                $existencias[$quien] ??= Existencia::query()->where(['bodega_id' => $bodega->id, 'lote_id' => $lotes[$quien]->id])->sole()->id;
            } else {
                rn06Ajuste($existencias[$quien], $cantidad)->assertCreated();
            }
            $esperado[$quien] += $cantidad;

            // El invariante se cumple después de CADA operación, no solo al final.
            rn06AfirmarCadena($existencias[$quien]);
        }

        foreach ($existencias as $quien => $id) {
            expect(rn06Stock($id))->toBe($esperado[$quien]);
            rn06AfirmarCadena($id);
        }

        expect(rn06TotalMovimientos())->toBe(count($pasos))
            ->and((int) DB::table('movimientos_kardex')->sum('cantidad'))->toBe(array_sum($esperado));
    })->with([
        'entradas y ajustes intercalados' => [[
            ['X', 'E', 10], ['Y', 'E', 7], ['X', 'A', 5], ['X', 'A', -3], ['Y', 'A', -7],
            ['X', 'E', 4], ['Y', 'E', 2], ['X', 'A', -16], ['Y', 'A', 1],
        ]],
        'baja a 0 exacto y vuelve a subir' => [[
            ['X', 'E', 5], ['X', 'A', -5], ['X', 'A', 3], ['X', 'E', 9], ['X', 'A', -12],
        ]],
        'muchos ajustes de 1 unidad hasta 0' => [[
            ['X', 'E', 3], ['X', 'A', -1], ['X', 'A', -1], ['X', 'A', 1], ['X', 'A', -1], ['X', 'A', -1], ['X', 'A', 1], ['X', 'A', -1],
        ]],
    ]);

    it('RN-06-AC2 RN-06-AC7: operaciones rechazadas en medio de la secuencia no rompen la cadena ni la suma', function () {
        $existencia = rn06ExistenciaConStock(6);

        rn06Ajuste($existencia->id, -2)->assertCreated();                 // 4
        esperarError(rn06Ajuste($existencia->id, -5), 409, 'STOCK_INSUFICIENTE');
        esperarError(rn06Ajuste($existencia->id, 0), 422, 'VALIDACION');
        rn06Ajuste($existencia->id, -4)->assertCreated();                 // 0
        esperarError(rn06Ajuste($existencia->id, -1), 409, 'STOCK_INSUFICIENTE');
        rn06Ajuste($existencia->id, 2)->assertCreated();                  // 2

        expect(rn06Stock($existencia->id))->toBe(2)
            ->and(rn06Movimientos($existencia->id))->toHaveCount(4);
        rn06AfirmarCadena($existencia->id);
    });

    it('RN-06-AC2: dos existencias del mismo lote en bodegas distintas tienen cadenas independientes', function () {
        $lote = rn06LoteVigente();
        $b1 = Bodega::factory()->create();
        $b2 = Bodega::factory()->create();

        $e1 = rn06ExistenciaConStock(10, $b1, $lote);
        $e2 = rn06ExistenciaConStock(3, $b2, $lote);
        rn06Ajuste($e1->id, -4)->assertCreated();
        rn06Ajuste($e2->id, 6)->assertCreated();

        expect(rn06Stock($e1->id))->toBe(6)->and(rn06Stock($e2->id))->toBe(9);
        rn06AfirmarCadena($e1->id);
        rn06AfirmarCadena($e2->id);
    });

    it('RN-06-AC2: la BD rechaza movimientos con saldos incoherentes, saldo negativo o cantidad 0 (23514)', function (array $fila) {
        $existencia = Existencia::factory()->create(['cantidad' => 10]);
        $usuario = usuarioConRol('regente_farmacia');

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($existencia, $usuario, $fila)
        ));

        expect($sqlstate)->toBe('23514')
            ->and(rn06TotalMovimientos())->toBe(0);
    })->with([
        'cantidad 0' => [['cantidad' => 0, 'saldo_anterior' => 3, 'saldo_posterior' => 3]],
        'saldo_posterior negativo' => [['cantidad' => -3, 'saldo_anterior' => 2, 'saldo_posterior' => -1]],
        'saldo_anterior negativo (S-40)' => [['tipo' => 'ENTRADA', 'motivo' => null, 'cantidad' => 5, 'saldo_anterior' => -5, 'saldo_posterior' => 0]],
        'posterior ≠ anterior + cantidad (por exceso)' => [['cantidad' => 5, 'saldo_anterior' => 10, 'saldo_posterior' => 16]],
        'posterior ≠ anterior + cantidad (por defecto)' => [['cantidad' => 5, 'saldo_anterior' => 10, 'saldo_posterior' => 14]],
        'signo invertido (anterior − cantidad)' => [['cantidad' => -5, 'saldo_anterior' => 10, 'saldo_posterior' => 15]],
        'cuadre que desbordaría integer' => [['cantidad' => 2147483647, 'saldo_anterior' => 2147483647, 'saldo_posterior' => -2]],
    ]);
});

/*
|--------------------------------------------------------------------------
| AC3: inmutabilidad en Eloquent
|--------------------------------------------------------------------------
*/

describe('RN-06-AC3 inmutabilidad en Eloquent', function () {
    beforeEach(function () {
        rn06Congelar();
        $this->existencia = rn06ExistenciaConStock(10);
        rn06Ajuste($this->existencia->id, -3)->assertCreated();
        $this->movimiento = MovimientoKardex::query()->where('tipo', 'AJUSTE')->sole();
        $this->antes = rn06Instantanea();
    });

    it('RN-06-AC3 S-39: update(), delete(), save() tras cambiar un atributo, forceDelete(), destroy(), increment()/decrement(), touch() y sus variantes Quietly/OrFail lanzan KardexInmutableException y la fila no cambia', function (Closure $operacion) {
        $movimiento = $this->movimiento;
        $error = null;

        try {
            DB::transaction(fn () => $operacion($movimiento));
        } catch (Throwable $e) {
            $error = $e;
        }

        // S-39: si llegara a una respuesta HTTP sería 409 KARDEX_INMUTABLE.
        expect($error)->toBeInstanceOf(KardexInmutableException::class)
            ->and($error->codigo())->toBe('KARDEX_INMUTABLE')
            ->and($error->status())->toBe(409)
            ->and($error->getMessage())->toStartWith('Los movimientos de kardex son inmutables:')
            ->and($error->getMessage())->toContain('Registre un ajuste.');

        expect(rn06Instantanea())->toBe($this->antes)
            ->and(MovimientoKardex::query()->whereKey($movimiento->id)->exists())->toBeTrue();
    })->with([
        'update([...])' => [fn (MovimientoKardex $m) => $m->update(['cantidad' => -1, 'saldo_posterior' => 9])],
        'update([]) sin cambios' => [fn (MovimientoKardex $m) => $m->update([])],
        'save() tras cambiar motivo' => [function (MovimientoKardex $m) {
            $m->motivo = 'Motivo reescrito por Eloquent';
            $m->save();
        }],
        'save() tras forceFill de cantidad y saldos coherentes' => [fn (MovimientoKardex $m) => $m->forceFill(['cantidad' => -1, 'saldo_posterior' => 9])->save()],
        'delete()' => [fn (MovimientoKardex $m) => $m->delete()],
        'forceDelete()' => [fn (MovimientoKardex $m) => $m->forceDelete()],
        'destroy(id)' => [fn (MovimientoKardex $m) => MovimientoKardex::destroy($m->id)],
        'increment(cantidad)' => [fn (MovimientoKardex $m) => $m->increment('saldo_posterior')],
        'decrement(saldo_anterior)' => [fn (MovimientoKardex $m) => $m->decrement('saldo_anterior')],
        'incrementEach([...])' => [fn (MovimientoKardex $m) => $m->incrementEach(['saldo_anterior' => 1, 'saldo_posterior' => 1])],
        'updateQuietly([...])' => [fn (MovimientoKardex $m) => $m->updateQuietly(['motivo' => 'Cambiado con updateQuietly'])],
        'deleteOrFail()' => [fn (MovimientoKardex $m) => $m->deleteOrFail()],
        'touch()' => [fn (MovimientoKardex $m) => $m->touch()],
        'push()' => [function (MovimientoKardex $m) {
            $m->forceFill(['motivo' => 'Motivo reescrito con push()']);
            $m->push();
        }],
    ]);

    it('RN-06-AC3 RN-06-AC4: los atajos que saltan los eventos del modelo (query()->update/delete, saveQuietly, deleteQuietly) los frena el trigger y la fila no cambia', function (Closure $operacion) {
        $movimiento = $this->movimiento;
        $error = null;

        try {
            DB::transaction(fn () => $operacion($movimiento));
        } catch (Throwable $e) {
            $error = $e;
        }

        expect($error)->not->toBeNull('la operación sobre el kardex no fue rechazada');
        if ($error instanceof QueryException) {
            expect((string) $error->getCode())->toBeIn(RN06_SQLSTATE_TRIGGER);
        } else {
            expect($error)->toBeInstanceOf(KardexInmutableException::class);
        }

        expect(rn06Instantanea())->toBe($this->antes);
    })->with([
        'query()->update' => [fn (MovimientoKardex $m) => MovimientoKardex::query()->whereKey($m->id)->update(['motivo' => 'Cambiado por el builder'])],
        'query()->delete' => [fn (MovimientoKardex $m) => MovimientoKardex::query()->whereKey($m->id)->delete()],
        'query()->delete() de toda la tabla' => [fn (MovimientoKardex $m) => MovimientoKardex::query()->delete()],
        'saveQuietly()' => [function (MovimientoKardex $m) {
            $m->motivo = 'Cambiado en silencio con saveQuietly';
            $m->saveQuietly();
        }],
        'deleteQuietly()' => [fn (MovimientoKardex $m) => $m->deleteQuietly()],
        'withoutEvents + delete' => [fn (MovimientoKardex $m) => MovimientoKardex::withoutEvents(fn () => $m->delete())],
        'incrementQuietly() (sin evento updating)' => [fn (MovimientoKardex $m) => $m->incrementQuietly('saldo_posterior')],
    ]);

    it('RN-06-AC4 S-39: si el trigger de inmutabilidad llega a una petición (UPDATE/DELETE por el builder) responde 409 KARDEX_INMUTABLE sin SQL ni valores en la respuesta ni en el log, y la fila no cambia', function (string $operacion) {
        $log = rn06LogDeTest();
        // Ruta temporal que simula un fallo de programación. En un savepoint: el error no debe
        // abortar la transacción de RefreshDatabase.
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/prueba-kardex-inmutable/{movimiento}', fn (string $movimiento) => DB::transaction(
            fn () => $operacion === 'update'
                ? MovimientoKardex::query()->whereKey((int) $movimiento)->update(['motivo' => 'MotivoSecreto4242 reescrito'])
                : MovimientoKardex::query()->whereKey((int) $movimiento)->delete(),
        ))->whereNumber('movimiento');
        actuarComo('regente_farmacia');

        $r = $this->postJson('/api/v1/prueba-kardex-inmutable/'.$this->movimiento->id);

        esperarError($r, 409, 'KARDEX_INMUTABLE');
        expect($r->json('error.mensaje'))->toBe('Los movimientos de kardex son inmutables. Registre un ajuste.')
            ->and($r->json('error.detalles'))->toBe([])
            ->and($r->getContent())->not->toContain('SQLSTATE')
            ->and($r->getContent())->not->toContain('MotivoSecreto4242')
            ->and(strtolower((string) $r->getContent()))->not->toContain('movimientos_kardex')
            ->and(rn06Instantanea())->toBe($this->antes);

        $contenido = (string) file_get_contents($log);
        expect($contenido)->toContain('23001')
            ->and($contenido)->not->toContain('MotivoSecreto4242')
            ->and($contenido)->not->toContain(RN06_MOTIVO)
            ->and(strtolower($contenido))->not->toContain($operacion === 'update' ? 'update "movimientos_kardex"' : 'delete from');
    })->with(['update', 'delete']);

    it('RN-06-AC4 S-39: otro 23001 (restrict_violation) que no viene del trigger del kardex sigue siendo 500 ERROR_INTERNO', function () {
        Route::middleware(['api', 'auth:sanctum'])->post('api/v1/prueba-otro-23001', fn () => DB::transaction(
            fn () => DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'otra restricción de prueba' USING ERRCODE = 'restrict_violation'; END $$"),
        ));
        actuarComo('regente_farmacia');

        esperarError($this->postJson('/api/v1/prueba-otro-23001'), 500, 'ERROR_INTERNO');
        expect(rn06Instantanea())->toBe($this->antes);
    });

    it('RN-06-AC3: la excepción no impide crear movimientos nuevos (solo INSERT): la creación sigue funcionando', function () {
        rn06Ajuste($this->existencia->id, 2)->assertCreated();

        expect(rn06Movimientos($this->existencia->id))->toHaveCount(3);
        rn06AfirmarCadena($this->existencia->id);
    });
});

/*
|--------------------------------------------------------------------------
| AC4: SQL directo (trigger) y CHECKs
|--------------------------------------------------------------------------
*/

describe('RN-06-AC4 inmutabilidad en la BD', function () {
    beforeEach(function () {
        rn06Congelar();
        $this->existencia = rn06ExistenciaConStock(10);
        rn06Ajuste($this->existencia->id, -3)->assertCreated();
        $this->ajuste = DB::table('movimientos_kardex')->where('tipo', 'AJUSTE')->sole();
        $this->otroUsuario = usuarioConRol('regente_farmacia');
        $this->antes = rn06Instantanea();
    });

    it('RN-06-AC4: UPDATE directo (aunque deje la fila válida para los CHECK) es rechazado por el trigger y la fila no cambia', function (Closure $sql) {
        $sqlstate = rn06Sqlstate(fn () => $sql($this->ajuste, $this->otroUsuario));

        expect($sqlstate)->toBeIn(RN06_SQLSTATE_TRIGGER)
            ->and(rn06Instantanea())->toBe($this->antes);
    })->with([
        'DB::update motivo' => [fn (object $m) => DB::update('UPDATE movimientos_kardex SET motivo = ? WHERE id = ?', ['Otro motivo válido y largo', $m->id])],
        'DB::update cantidad y saldos coherentes' => [fn (object $m) => DB::update('UPDATE movimientos_kardex SET cantidad = -1, saldo_posterior = saldo_anterior - 1 WHERE id = ?', [$m->id])],
        'DB::update usuario_id' => [fn (object $m, User $otro) => DB::update('UPDATE movimientos_kardex SET usuario_id = ? WHERE id = ?', [$otro->id, $m->id])],
        'DB::update created_at' => [fn (object $m) => DB::update("UPDATE movimientos_kardex SET created_at = created_at - interval '1 day' WHERE id = ?", [$m->id])],
        'DB::update sin cambios (SET col = col)' => [fn (object $m) => DB::update('UPDATE movimientos_kardex SET cantidad = cantidad WHERE id = ?', [$m->id])],
        'DB::statement UPDATE de toda la tabla' => [fn () => DB::statement('UPDATE movimientos_kardex SET motivo = motivo')],
        'DB::table()->update' => [fn (object $m) => DB::table('movimientos_kardex')->where('id', $m->id)->update(['motivo' => 'Cambiado por query builder'])],
    ]);

    it('RN-06-AC4: DELETE directo es rechazado por el trigger y no se borra nada', function (Closure $sql) {
        $sqlstate = rn06Sqlstate(fn () => $sql($this->ajuste));

        expect($sqlstate)->toBeIn(RN06_SQLSTATE_TRIGGER)
            ->and(rn06Instantanea())->toBe($this->antes);
    })->with([
        'DB::delete por id' => [fn (object $m) => DB::delete('DELETE FROM movimientos_kardex WHERE id = ?', [$m->id])],
        'DB::delete de toda la tabla' => [fn () => DB::delete('DELETE FROM movimientos_kardex')],
        'DB::statement DELETE' => [fn (object $m) => DB::statement('DELETE FROM movimientos_kardex WHERE id = '.(int) $m->id)],
        'DB::table()->delete' => [fn (object $m) => DB::table('movimientos_kardex')->where('id', $m->id)->delete()],
        'DELETE … RETURNING' => [fn (object $m) => DB::select('DELETE FROM movimientos_kardex WHERE id = ? RETURNING id', [$m->id])],
    ]);

    it('RN-06-AC4 S-40: TRUNCATE también borra movimientos, así que la BD lo rechaza y no se borra nada', function (string $sql) {
        $sqlstate = rn06Sqlstate(fn () => DB::statement($sql));

        expect($sqlstate)->not->toBeNull('TRUNCATE borró el kardex: "nunca se borran" (RN-06)')
            ->and(rn06Instantanea())->toBe($this->antes);
    })->with([
        'TRUNCATE movimientos_kardex' => ['TRUNCATE movimientos_kardex'],
        'TRUNCATE existencias CASCADE' => ['TRUNCATE existencias CASCADE'],
    ]);

    it('RN-06-AC4: no se puede borrar la existencia ni el usuario de un movimiento (el kardex no queda huérfano ni se borra en cascada)', function (string $quien) {
        $sqlstate = $quien === 'existencia'
            ? rn06Sqlstate(fn () => DB::table('existencias')->where('id', $this->existencia->id)->delete())
            : rn06Sqlstate(fn () => DB::table('users')->where('id', $this->ajuste->usuario_id)->delete());

        expect($sqlstate)->not->toBeNull()
            ->and(rn06Instantanea())->toBe($this->antes)
            ->and(DB::table('users')->where('id', $this->ajuste->usuario_id)->exists())->toBeTrue();
    })->with(['existencia', 'usuario']);

    it('RN-06-AC4: el trigger es BEFORE UPDATE y BEFORE DELETE sobre movimientos_kardex', function () {
        $eventos = DB::table('information_schema.triggers')
            ->where('event_object_table', 'movimientos_kardex')
            ->where('action_timing', 'BEFORE')
            ->pluck('event_manipulation')
            ->unique()->sort()->values()->all();

        expect($eventos)->toContain('DELETE')->toContain('UPDATE');
    });

    it('RN-06-AC4 S-33: un INSERT que viola la coherencia signo/tipo o el motivo obligatorio del AJUSTE es rechazado (23514)', function (array $fila) {
        $usuario = usuarioConRol('regente_farmacia');
        $antes = rn06Instantanea();

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($this->existencia, $usuario, $fila)
        ));

        expect($sqlstate)->toBe('23514')
            ->and(rn06Instantanea())->toBe($antes);
    })->with([
        'ENTRADA negativa' => [['tipo' => 'ENTRADA', 'motivo' => null, 'cantidad' => -2, 'saldo_anterior' => 7, 'saldo_posterior' => 5]],
        'ENTRADA_TRASLADO negativa' => [['tipo' => 'ENTRADA_TRASLADO', 'motivo' => null, 'documento_tipo' => 'traslado', 'documento_id' => 1, 'cantidad' => -2, 'saldo_anterior' => 7, 'saldo_posterior' => 5]],
        'SALIDA_DISPENSACION positiva' => [['tipo' => 'SALIDA_DISPENSACION', 'motivo' => null, 'documento_tipo' => 'dispensacion', 'documento_id' => 1, 'cantidad' => 2, 'saldo_anterior' => 7, 'saldo_posterior' => 9]],
        'SALIDA_TRASLADO positiva' => [['tipo' => 'SALIDA_TRASLADO', 'motivo' => null, 'documento_tipo' => 'traslado', 'documento_id' => 1, 'cantidad' => 2, 'saldo_anterior' => 7, 'saldo_posterior' => 9]],
        'AJUSTE sin motivo (NULL)' => [['motivo' => null]],
        'AJUSTE con motivo vacío' => [['motivo' => '']],
        'AJUSTE con motivo en blanco' => [['motivo' => '          ']],
        'AJUSTE con motivo de 9 caracteres' => [['motivo' => 'abcdefghi']],
        'AJUSTE cantidad 0' => [['cantidad' => 0, 'saldo_anterior' => 7, 'saldo_posterior' => 7]],
    ]);

    it('RN-06-AC4 sin falsos positivos: cada tipo con su signo correcto (y AJUSTE en ambos sentidos con motivo) sí se inserta', function (array $fila) {
        $usuario = usuarioConRol('regente_farmacia');
        $antes = rn06TotalMovimientos();

        $sqlstate = rn06Sqlstate(fn () => DB::table('movimientos_kardex')->insert(
            rn06FilaMovimiento($this->existencia, $usuario, $fila)
        ));

        expect($sqlstate)->toBeNull()
            ->and(rn06TotalMovimientos())->toBe($antes + 1);
    })->with([
        'ENTRADA +' => [['tipo' => 'ENTRADA', 'motivo' => null, 'cantidad' => 2, 'saldo_anterior' => 7, 'saldo_posterior' => 9]],
        'ENTRADA_TRASLADO +' => [['tipo' => 'ENTRADA_TRASLADO', 'motivo' => null, 'documento_tipo' => 'traslado', 'documento_id' => 1, 'cantidad' => 2, 'saldo_anterior' => 7, 'saldo_posterior' => 9]],
        'SALIDA_DISPENSACION −' => [['tipo' => 'SALIDA_DISPENSACION', 'motivo' => null, 'documento_tipo' => 'dispensacion', 'documento_id' => 1, 'cantidad' => -2, 'saldo_anterior' => 7, 'saldo_posterior' => 5]],
        'SALIDA_TRASLADO −' => [['tipo' => 'SALIDA_TRASLADO', 'motivo' => null, 'documento_tipo' => 'traslado', 'documento_id' => 1, 'cantidad' => -2, 'saldo_anterior' => 7, 'saldo_posterior' => 5]],
        'AJUSTE +' => [['cantidad' => 2, 'saldo_anterior' => 7, 'saldo_posterior' => 9]],
        'AJUSTE −' => [['cantidad' => -7, 'saldo_anterior' => 7, 'saldo_posterior' => 0]],
        'AJUSTE motivo de 10 caracteres' => [['motivo' => 'abcdefghij']],
    ]);
});

/*
|--------------------------------------------------------------------------
| AC5: sin rutas de edición del kardex
|--------------------------------------------------------------------------
*/

describe('RN-06-AC5 rutas de edición del kardex', function () {
    beforeEach(function () {
        rn06Congelar();
        $this->existencia = rn06ExistenciaConStock(10);
        rn06Ajuste($this->existencia->id, -3)->assertCreated();
        $this->movimiento = DB::table('movimientos_kardex')->where('tipo', 'AJUSTE')->sole();
        $this->antes = rn06Instantanea();
        olvidarAutenticacion();
    });

    it('RN-06-AC5: PUT, PATCH y DELETE sobre /api/v1/kardex/{id} → 405 METODO_NO_PERMITIDO y nada cambia', function (string $metodo, ?string $rol) {
        if ($rol !== null) {
            actuarComo($rol);
        }

        $r = $this->json($metodo, RN06_URI_KARDEX.'/'.$this->movimiento->id, [
            'cantidad' => -1,
            'saldo_posterior' => 9,
            'motivo' => 'Intento de editar el kardex por la API',
        ]);

        esperarError($r, 405, 'METODO_NO_PERMITIDO');
        $permitidos = strtoupper((string) $r->headers->get('Allow'));
        expect($permitidos)->not->toContain('PUT')
            ->and($permitidos)->not->toContain('PATCH')
            ->and($permitidos)->not->toContain('DELETE')
            ->and(rn06Instantanea())->toBe($this->antes);
    })->with(['PUT', 'PATCH', 'DELETE'])->with([
        'regente' => ['regente_farmacia'],
        'auditor' => ['auditor'],
        'auxiliar' => ['auxiliar_farmacia'],
        'sin token' => [null],
    ]);

    it('RN-06-AC5: también sobre un id inexistente → 405 (el método se rechaza siempre, sin revelar si existe)', function (string $metodo) {
        actuarComo('regente_farmacia');

        esperarError($this->json($metodo, RN06_URI_KARDEX.'/987654321', ['motivo' => 'Intento de editar el kardex']), 405, 'METODO_NO_PERMITIDO');
        expect(rn06Instantanea())->toBe($this->antes);
    })->with(['PUT', 'PATCH', 'DELETE']);

    it('RN-06-AC5: no hay alta directa de movimientos: POST, PUT, PATCH y DELETE sobre /api/v1/kardex → 405 y no se crea nada', function (string $metodo) {
        $regente = actuarComo('regente_farmacia');

        $r = $this->json($metodo, RN06_URI_KARDEX, [
            'tipo' => 'ENTRADA',
            'existencia_id' => $this->existencia->id,
            'cantidad' => 1000,
            'saldo_anterior' => 7,
            'saldo_posterior' => 1007,
            'usuario_id' => $regente->id,
        ]);

        esperarError($r, 405, 'METODO_NO_PERMITIDO');
        expect(rn06Instantanea())->toBe($this->antes);
    })->with(['POST', 'PUT', 'PATCH', 'DELETE']);
});

/*
|--------------------------------------------------------------------------
| AC6: POST /api/v1/inventario/ajustes
|--------------------------------------------------------------------------
*/

describe('RN-06-AC6 ajustes de inventario', function () {
    beforeEach(fn () => rn06Congelar());

    it('RN-06-AC6: un regente ajusta (positivo, negativo, hasta 0 exacto, desde 0) → 201, stock ajustado y movimiento AJUSTE con el motivo', function (int $inicial, int $cantidad) {
        $existencia = $inicial > 0 ? rn06ExistenciaConStock($inicial) : Existencia::factory()->create(['cantidad' => 0]);
        $regente = usuarioConRol('regente_farmacia');
        $antes = rn06TotalMovimientos();

        $r = rn06Ajuste($existencia->id, $cantidad, 'Ajuste por conteo cíclico semanal', $regente);

        $r->assertCreated();
        expect(rn06Stock($existencia->id))->toBe($inicial + $cantidad)
            ->and(rn06TotalMovimientos())->toBe($antes + 1);

        $m = DB::table('movimientos_kardex')->orderByDesc('id')->first();
        expect($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->existencia_id)->toBe($existencia->id)
            ->and((int) $m->cantidad)->toBe($cantidad)
            ->and((int) $m->saldo_anterior)->toBe($inicial)
            ->and((int) $m->saldo_posterior)->toBe($inicial + $cantidad)
            ->and($m->motivo)->toBe('Ajuste por conteo cíclico semanal')
            ->and((int) $m->usuario_id)->toBe($regente->id);

        rn06AfirmarCadena($existencia->id);
    })->with([
        '+5 sobre 10' => [10, 5],
        '−4 sobre 10' => [10, -4],
        '−10 sobre 10 (deja exactamente 0)' => [10, -10],
        '−1 sobre 1' => [1, -1],
        '+3 sobre 0' => [0, 3],
        '+1 sobre 10' => [10, 1],
    ]);

    it('RN-06-AC6 S-33 frontera: motivo de exactamente 10 caracteres (también multibyte) se acepta (201)', function (string $motivo) {
        $existencia = rn06ExistenciaConStock(10);

        rn06Ajuste($existencia->id, -1, $motivo)->assertCreated();

        expect(DB::table('movimientos_kardex')->where('tipo', 'AJUSTE')->value('motivo'))->toBe($motivo)
            ->and(rn06Stock($existencia->id))->toBe(9);
    })->with([
        'ASCII 10' => ['abcdefghij'],
        'multibyte 10 (ñ)' => [str_repeat('ñ', 10)],
        'con acentos' => ['Daño caja.'],
    ]);

    it('RN-06-AC6: el cuerpo no puede fijar tipo, usuario ni saldos: se ignoran y el movimiento es AJUSTE del usuario autenticado con saldos calculados', function () {
        $existencia = rn06ExistenciaConStock(10);
        $regente = usuarioConRol('regente_farmacia');
        $otro = usuarioConRol('regente_farmacia');

        $r = rn06Ajuste($existencia->id, -2, RN06_MOTIVO, $regente, [
            'tipo' => 'ENTRADA',
            'usuario_id' => $otro->id,
            'saldo_anterior' => 500,
            'saldo_posterior' => 498,
            'documento_tipo' => 'dispensacion',
            'documento_id' => 1,
        ]);

        $r->assertCreated();
        $m = DB::table('movimientos_kardex')->orderByDesc('id')->first();
        expect($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->usuario_id)->toBe($regente->id)
            ->and((int) $m->saldo_anterior)->toBe(10)
            ->and((int) $m->saldo_posterior)->toBe(8)
            ->and(rn06Stock($existencia->id))->toBe(8);
        rn06AfirmarCadena($existencia->id);
    });

    it('RN-06-AC6 RN-06-AC7 S-32 S-33: datos inválidos (sin motivo, motivo < 10, cantidad 0/decimal/booleana, existencia inválida) → 422 VALIDACION con el campo y sin cambios', function (Closure $mutar, string $campo) {
        $existencia = rn06ExistenciaConStock(10);
        $cuerpo = $mutar(['existencia_id' => $existencia->id, 'cantidad' => -2, 'motivo' => RN06_MOTIVO]);
        $antes = rn06Instantanea();
        Sanctum::actingAs(usuarioConRol('regente_farmacia'));

        $r = $this->postJson(RN06_URI_AJUSTES, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn06Instantanea())->toBe($antes);
    })->with([
        'sin motivo' => [function (array $c) {
            unset($c['motivo']);

            return $c;
        }, 'motivo'],
        'motivo null' => [fn (array $c) => ['motivo' => null] + $c, 'motivo'],
        'motivo vacío' => [fn (array $c) => ['motivo' => ''] + $c, 'motivo'],
        'motivo de 9 caracteres' => [fn (array $c) => ['motivo' => 'abcdefghi'] + $c, 'motivo'],
        'motivo de 9 multibyte' => [fn (array $c) => ['motivo' => str_repeat('ñ', 9)] + $c, 'motivo'],
        'motivo solo espacios (10)' => [fn (array $c) => ['motivo' => str_repeat(' ', 10)] + $c, 'motivo'],
        'motivo de 9 rellenado con espacios' => [fn (array $c) => ['motivo' => '   abcdefghi   '] + $c, 'motivo'],
        'motivo de 501 caracteres' => [fn (array $c) => ['motivo' => str_repeat('a', 501)] + $c, 'motivo'],
        'motivo de 501 multibyte' => [fn (array $c) => ['motivo' => str_repeat('ñ', 501)] + $c, 'motivo'],
        'motivo con salto de línea (Cc)' => [fn (array $c) => ['motivo' => "Conteo físico\nde cierre"] + $c, 'motivo'],
        'motivo con tabulador (Cc)' => [fn (array $c) => ['motivo' => "Conteo físico\tde cierre"] + $c, 'motivo'],
        'motivo con NUL (Cc)' => [fn (array $c) => ['motivo' => "Conteo físico\0de cierre"] + $c, 'motivo'],
        'motivo con espacio de ancho cero U+200B (Cf)' => [fn (array $c) => ['motivo' => "Conteo físico\u{200B}de cierre"] + $c, 'motivo'],
        'motivo con separador de línea U+2028 (Zl)' => [fn (array $c) => ['motivo' => "Conteo físico\u{2028}de cierre"] + $c, 'motivo'],
        'motivo con separador de párrafo U+2029 (Zp)' => [fn (array $c) => ['motivo' => "Conteo físico\u{2029}de cierre"] + $c, 'motivo'],
        'motivo numérico' => [fn (array $c) => ['motivo' => 1234567890123] + $c, 'motivo'],
        'motivo array' => [fn (array $c) => ['motivo' => ['Conteo físico de cierre']] + $c, 'motivo'],
        'cantidad 0' => [fn (array $c) => ['cantidad' => 0] + $c, 'cantidad'],
        'cantidad "0"' => [fn (array $c) => ['cantidad' => '0'] + $c, 'cantidad'],
        'sin cantidad' => [function (array $c) {
            unset($c['cantidad']);

            return $c;
        }, 'cantidad'],
        'cantidad null' => [fn (array $c) => ['cantidad' => null] + $c, 'cantidad'],
        'cantidad decimal 1.5' => [fn (array $c) => ['cantidad' => 1.5] + $c, 'cantidad'],
        'cantidad decimal −0.5' => [fn (array $c) => ['cantidad' => -0.5] + $c, 'cantidad'],
        'cantidad true' => [fn (array $c) => ['cantidad' => true] + $c, 'cantidad'],
        'cantidad false' => [fn (array $c) => ['cantidad' => false] + $c, 'cantidad'],
        'cantidad texto' => [fn (array $c) => ['cantidad' => 'menos dos'] + $c, 'cantidad'],
        'cantidad array' => [fn (array $c) => ['cantidad' => [-2]] + $c, 'cantidad'],
        'cantidad 10^12 (fuera de integer)' => [fn (array $c) => ['cantidad' => 1_000_000_000_000] + $c, 'cantidad'],
        'cantidad −10^12 (fuera de integer)' => [fn (array $c) => ['cantidad' => -1_000_000_000_000] + $c, 'cantidad'],
        'sin existencia_id' => [function (array $c) {
            unset($c['existencia_id']);

            return $c;
        }, 'existencia_id'],
        'existencia_id inexistente' => [fn (array $c) => ['existencia_id' => 987654321] + $c, 'existencia_id'],
        'existencia_id 0' => [fn (array $c) => ['existencia_id' => 0] + $c, 'existencia_id'],
        'existencia_id negativo' => [fn (array $c) => ['existencia_id' => -1] + $c, 'existencia_id'],
        'existencia_id texto' => [fn (array $c) => ['existencia_id' => 'abc'] + $c, 'existencia_id'],
        'existencia_id booleano' => [fn (array $c) => ['existencia_id' => true] + $c, 'existencia_id'],
        'existencia_id decimal' => [fn (array $c) => ['existencia_id' => 1.5] + $c, 'existencia_id'],
        'existencia_id desmesurado' => [fn (array $c) => ['existencia_id' => '99999999999999999999'] + $c, 'existencia_id'],
    ]);

    it('RN-06-AC6 RN-03-AC4 S-32: un ajuste negativo mayor que la existencia → 409 STOCK_INSUFICIENTE, sin movimiento y stock igual', function (int $inicial, int $cantidad) {
        $existencia = $inicial > 0 ? rn06ExistenciaConStock($inicial) : Existencia::factory()->create(['cantidad' => 0]);
        $antes = rn06Instantanea();

        esperarError(rn06Ajuste($existencia->id, $cantidad), 409, 'STOCK_INSUFICIENTE');

        expect(rn06Instantanea())->toBe($antes)
            ->and(rn06Stock($existencia->id))->toBe($inicial);
    })->with([
        'stock + 1' => [10, -11],
        'muy por encima' => [10, -1000],
        'sobre existencia en 0' => [0, -1],
        'un millón (tope de S-32)' => [10, -1_000_000],
    ]);

    it('RN-06-AC6 S-34: se ajusta sobre un lote ya vencido (p. ej. dar de baja stock vencido) → 201, stock ajustado y movimiento AJUSTE encadenado', function (string $ahora, int $cantidad) {
        $lote = Lote::factory()->create(['fecha_vencimiento' => '2026-11-10']);
        $existencia = rn06ExistenciaConStock(10, null, $lote);
        rn06Congelar($ahora);
        expect($lote->fresh()?->fecha_vencimiento->toDateString() < Carbon::parse($ahora, 'America/Bogota')->toDateString())->toBeTrue('el lote debería estar vencido');
        $antes = rn06TotalMovimientos();

        $r = rn06Ajuste($existencia->id, $cantidad, 'Baja de stock vencido en revisión');

        $r->assertCreated();
        $m = DB::table('movimientos_kardex')->orderByDesc('id')->first();
        expect(rn06Stock($existencia->id))->toBe(10 + $cantidad)
            ->and(rn06TotalMovimientos())->toBe($antes + 1)
            ->and($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->existencia_id)->toBe($existencia->id)
            ->and((int) $m->cantidad)->toBe($cantidad)
            ->and($m->motivo)->toBe('Baja de stock vencido en revisión');
        rn06AfirmarCadena($existencia->id);
    })->with([
        'vencido ayer, baja total −10' => ['2026-11-11 10:00:00', -10],
        'vencido ayer, −4' => ['2026-11-11 10:00:00', -4],
        'vencido hace meses, +3 (sobrante)' => ['2027-03-01 10:00:00', 3],
    ]);

    it('RN-06-AC6 S-34: se ajusta sobre una bodega o un producto inactivos → 201, stock ajustado y movimiento AJUSTE encadenado', function (bool $bodegaInactiva, bool $productoInactivo, int $cantidad) {
        $existencia = rn06ExistenciaConStock(10);
        if ($bodegaInactiva) {
            Bodega::query()->whereKey($existencia->bodega_id)->update(['activa' => false]);
        }
        if ($productoInactivo) {
            Producto::query()->whereKey($existencia->producto_id)->update(['activo' => false]);
        }
        $antes = rn06TotalMovimientos();

        $r = rn06Ajuste($existencia->id, $cantidad);

        $r->assertCreated();
        $m = DB::table('movimientos_kardex')->orderByDesc('id')->first();
        expect(rn06Stock($existencia->id))->toBe(10 + $cantidad)
            ->and(rn06TotalMovimientos())->toBe($antes + 1)
            ->and($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->existencia_id)->toBe($existencia->id)
            ->and((int) $m->cantidad)->toBe($cantidad)
            ->and(DB::table('bodegas')->where('id', $existencia->bodega_id)->value('activa'))->toBe(! $bodegaInactiva)
            ->and(DB::table('productos')->where('id', $existencia->producto_id)->value('activo'))->toBe(! $productoInactivo);
        rn06AfirmarCadena($existencia->id);
    })->with([
        'bodega inactiva, −3' => [true, false, -3],
        'producto inactivo, −10 (baja total)' => [false, true, -10],
        'bodega y producto inactivos, +2' => [true, true, 2],
    ]);

    it('RN-06-AC6 S-32: un ajuste fuera de ±1.000.000 → 422 VALIDACION en cantidad y sin cambios', function (int $cantidad) {
        $existencia = rn06ExistenciaConStock(10);
        $antes = rn06Instantanea();

        $r = rn06Ajuste($existencia->id, $cantidad);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('cantidad')
            ->and(rn06Instantanea())->toBe($antes)
            ->and(rn06Stock($existencia->id))->toBe(10);
    })->with([
        '−1.000.001' => [-1_000_001],
        '+1.000.001' => [1_000_001],
    ]);

    it('RN-06-AC6 S-32 frontera: un ajuste de +1.000.000 exacto se acepta (201)', function () {
        $existencia = rn06ExistenciaConStock(10);

        rn06Ajuste($existencia->id, 1_000_000)->assertCreated();

        expect(rn06Stock($existencia->id))->toBe(1_000_010);
        rn06AfirmarCadena($existencia->id);
    });

    it('RN-06-AC6 S-31 (aplicado a ajustes): un ajuste positivo que superaría 2.147.483.647 → 422 CANTIDAD_EXCEDE_MAXIMO sin cambios', function () {
        $existencia = Existencia::factory()->create(['cantidad' => 2_147_483_647]);
        $antes = rn06Instantanea();

        esperarError(rn06Ajuste($existencia->id, 1), 422, 'CANTIDAD_EXCEDE_MAXIMO');

        expect(rn06Instantanea())->toBe($antes);
    });

    it('RN-06-AC6 roles: solo el regente ajusta; auxiliar, médico, auditor y admin → 403 NO_AUTORIZADO sin cambios', function (string $rol) {
        $existencia = rn06ExistenciaConStock(10);
        $antes = rn06Instantanea();
        olvidarAutenticacion();

        $r = rn06Ajuste($existencia->id, -2, RN06_MOTIVO, usuarioConRol($rol));

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn06Instantanea())->toBe($antes);
    })->with(['auxiliar_farmacia', 'medico', 'auditor', 'admin']);

    it('RN-06-AC6 roles: el 403 llega aunque el cuerpo sea inválido o el ajuste exceda el stock (no se filtra información)', function (array $cuerpo) {
        $existencia = rn06ExistenciaConStock(10);
        $antes = rn06Instantanea();
        olvidarAutenticacion();
        actuarComo('auxiliar_farmacia');

        esperarError($this->postJson(RN06_URI_AJUSTES, ['existencia_id' => $existencia->id] + $cuerpo), 403, 'NO_AUTORIZADO');
        expect(rn06Instantanea())->toBe($antes);
    })->with([
        'cantidad 0' => [['cantidad' => 0, 'motivo' => RN06_MOTIVO]],
        'excede stock' => [['cantidad' => -50, 'motivo' => RN06_MOTIVO]],
    ]);

    it('RN-06-AC6 roles: sin token → 401 NO_AUTENTICADO sin cambios', function () {
        $existencia = rn06ExistenciaConStock(10);
        $antes = rn06Instantanea();
        olvidarAutenticacion();

        $r = $this->postJson(RN06_URI_AJUSTES, [
            'existencia_id' => $existencia->id, 'cantidad' => -2, 'motivo' => RN06_MOTIVO,
        ]);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn06Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| AC7: una operación fallida no deja movimientos
|--------------------------------------------------------------------------
*/

describe('RN-06-AC7 operaciones fallidas sin kardex', function () {
    beforeEach(function () {
        rn06Congelar();
        $this->bodega = Bodega::factory()->create();
        $this->producto = Producto::factory()->create();
        $this->lote = rn06LoteVigente($this->producto, 'RN06-VIG');
        $this->existencia = rn06ExistenciaConStock(10, $this->bodega, $this->lote);
    });

    it('RN-06-AC7: cada operación rechazada (409/422/403) deja inventario y kardex exactamente igual', function (Closure $preparar, int $status, string $codigo) {
        $operacion = $preparar($this);   // crea los datos extra del caso y devuelve la operación
        $antes = rn06Instantanea();

        esperarError($operacion(), $status, $codigo);

        expect(rn06Instantanea())->toBe($antes);
        rn06AfirmarCadena($this->existencia->id);
    })->with([
        'entrada con lote nuevo que vence hoy (LOTE_VENCIDO)' => [fn ($t) => fn () => rn06PostEntradaLoteNuevo($t, 'RN06-HOY', RN06_HOY, 5), 422, 'LOTE_VENCIDO'],
        'entrada con lote nuevo vencido ayer (LOTE_VENCIDO)' => [fn ($t) => fn () => rn06PostEntradaLoteNuevo($t, 'RN06-AYER', '2026-11-03', 5), 422, 'LOTE_VENCIDO'],
        'entrada con lote registrado vencido (LOTE_VENCIDO)' => [function ($t) {
            $vencido = Lote::factory()->create(['producto_id' => $t->producto->id, 'fecha_vencimiento' => '2026-10-01']);
            Existencia::factory()->paraLote($vencido)->create(['bodega_id' => $t->bodega->id, 'cantidad' => 4]);

            return fn () => rn06Entrada($t->bodega, $vencido, 5);
        }, 422, 'LOTE_VENCIDO'],
        'entrada con código existente y otra fecha (LOTE_NO_COINCIDE)' => [fn ($t) => fn () => rn06PostEntradaLoteNuevo($t, 'RN06-VIG', '2027-12-31', 5), 422, 'LOTE_NO_COINCIDE'],
        'entrada que desborda el saldo (CANTIDAD_EXCEDE_MAXIMO)' => [function ($t) {
            $lote = rn06LoteVigente($t->producto);
            Existencia::factory()->paraLote($lote)->create(['bodega_id' => $t->bodega->id, 'cantidad' => 2_147_000_000]);

            return fn () => rn06Entrada($t->bodega, $lote, 1_000_000);
        }, 422, 'CANTIDAD_EXCEDE_MAXIMO'],
        'entrada con cantidad 0 (VALIDACION)' => [fn ($t) => fn () => rn06Entrada($t->bodega, $t->lote, 0), 422, 'VALIDACION'],
        'ajuste negativo excesivo (STOCK_INSUFICIENTE)' => [fn ($t) => fn () => rn06Ajuste($t->existencia->id, -11), 409, 'STOCK_INSUFICIENTE'],
        'ajuste sin motivo (VALIDACION)' => [fn ($t) => fn () => rn06Ajuste($t->existencia->id, -1, null), 422, 'VALIDACION'],
        'ajuste cantidad 0 (VALIDACION)' => [fn ($t) => fn () => rn06Ajuste($t->existencia->id, 0), 422, 'VALIDACION'],
        'ajuste por auxiliar (NO_AUTORIZADO)' => [fn ($t) => fn () => rn06Ajuste($t->existencia->id, -1, RN06_MOTIVO, usuarioConRol('auxiliar_farmacia')), 403, 'NO_AUTORIZADO'],
        'entrada por auxiliar (NO_AUTORIZADO)' => [fn ($t) => fn () => rn06Entrada($t->bodega, $t->lote, 5, usuarioConRol('auxiliar_farmacia')), 403, 'NO_AUTORIZADO'],
    ]);

    it('RN-06-AC7 atomicidad: si el INSERT del kardex falla, la entrada se revierte entera (sin stock, lote ni existencia nuevos)', function (string $forma) {
        rn06RomperInsercionKardex();
        $antes = rn06Instantanea();

        $r = $forma === 'existencia existente'
            ? rn06Entrada($this->bodega, $this->lote, 5)
            : rn06PostEntradaLoteNuevo($this, 'RN06-ATOMICO', '2027-06-30', 5);

        esperarError($r, 500, 'ERROR_INTERNO');
        expect(rn06Instantanea())->toBe($antes)
            ->and(rn06Stock($this->existencia->id))->toBe(10);
    })->with(['existencia existente', 'lote nuevo']);

    it('RN-06-AC7 atomicidad: si el INSERT del kardex falla, el ajuste no cambia el stock', function (int $cantidad) {
        rn06RomperInsercionKardex();
        $antes = rn06Instantanea();

        esperarError(rn06Ajuste($this->existencia->id, $cantidad), 500, 'ERROR_INTERNO');

        expect(rn06Instantanea())->toBe($antes)
            ->and(rn06Stock($this->existencia->id))->toBe(10);
    })->with(['positivo' => [3], 'negativo' => [-3]]);
});

/** POST /inventario/entradas con lote por código + fecha (lote de la compra). */
function rn06PostEntradaLoteNuevo(object $t, string $codigo, string $fecha, int $cantidad): TestResponse
{
    Sanctum::actingAs(rn06Regente());

    return test()->postJson(RN06_URI_ENTRADAS, [
        'bodega_id' => $t->bodega->id,
        'producto_id' => $t->producto->id,
        'lote' => ['codigo' => $codigo, 'fecha_vencimiento' => $fecha],
        'cantidad' => $cantidad,
    ]);
}

/**
 * Inyección de fallo REAL en PostgreSQL (no un mock): un trigger BEFORE INSERT que aborta todo
 * INSERT en movimientos_kardex. Vive dentro de la transacción de RefreshDatabase y se revierte al
 * terminar el test.
 */
function rn06RomperInsercionKardex(): void
{
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION rn06_test_falla_insert() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            RAISE EXCEPTION 'fallo inyectado por el test RN-06-AC7';
        END;
        $$;
        CREATE TRIGGER rn06_test_falla_insert BEFORE INSERT ON movimientos_kardex
            FOR EACH ROW EXECUTE FUNCTION rn06_test_falla_insert();
        SQL);
}

/*
|--------------------------------------------------------------------------
| AC8: GET /api/v1/kardex
|--------------------------------------------------------------------------
*/

/**
 * Escenario fijo (hoy = 2026-11-04 Bogotá). P1 tiene L1 y L2; P2 tiene L3.
 *
 *   m1  d-3 10:00  ENTRADA  B1 L1 +10
 *   m2  d-2 10:00  ENTRADA  B1 L2 +20
 *   m3  d-2 11:00  ENTRADA  B1 L3 +30
 *   m4  d-1 10:00  ENTRADA  B2 L1 +40
 *   m5  d-1 12:00  AJUSTE   B1 L1 −4
 *   m6  d   09:00  ENTRADA  B2 L3 +50
 *   m7  d   09:30  AJUSTE   B2 L3 +5
 *
 * Existencias: E_B1L1 (m1, m5), E_B2L1 (m4), E_B2L3 (m6, m7).
 *
 * @return array{ids: array<string, int>, ent: array<string, int>}
 */
function rn06EscenarioKardex(): array
{
    $b1 = Bodega::factory()->create();
    $b2 = Bodega::factory()->create();
    $p1 = Producto::factory()->create();
    $p2 = Producto::factory()->create();
    $l1 = rn06LoteVigente($p1, 'RN06-L1');
    $l2 = rn06LoteVigente($p1, 'RN06-L2');
    $l3 = rn06LoteVigente($p2, 'RN06-L3');

    $ids = [];
    $ultimo = fn (): int => (int) DB::table('movimientos_kardex')->max('id');
    $existencia = fn (Bodega $b, Lote $l): int => Existencia::query()->where(['bodega_id' => $b->id, 'lote_id' => $l->id])->value('id');

    rn06Congelar('2026-11-01 10:00:00');
    rn06Entrada($b1, $l1, 10)->assertCreated();
    $ids['m1'] = $ultimo();
    rn06Congelar('2026-11-02 10:00:00');
    rn06Entrada($b1, $l2, 20)->assertCreated();
    $ids['m2'] = $ultimo();
    rn06Congelar('2026-11-02 11:00:00');
    rn06Entrada($b1, $l3, 30)->assertCreated();
    $ids['m3'] = $ultimo();
    rn06Congelar('2026-11-03 10:00:00');
    rn06Entrada($b2, $l1, 40)->assertCreated();
    $ids['m4'] = $ultimo();
    rn06Congelar('2026-11-03 12:00:00');
    rn06Ajuste($existencia($b1, $l1), -4)->assertCreated();
    $ids['m5'] = $ultimo();
    rn06Congelar('2026-11-04 09:00:00');
    rn06Entrada($b2, $l3, 50)->assertCreated();
    $ids['m6'] = $ultimo();
    rn06Congelar('2026-11-04 09:30:00');
    rn06Ajuste($existencia($b2, $l3), 5)->assertCreated();
    $ids['m7'] = $ultimo();

    rn06Congelar(RN06_HOY.' 18:00:00');

    return [
        'ids' => $ids,
        'ent' => [
            'B1' => $b1->id, 'B2' => $b2->id, 'P1' => $p1->id, 'P2' => $p2->id, 'L1' => $l1->id, 'L2' => $l2->id, 'L3' => $l3->id,
            'E_B1L1' => $existencia($b1, $l1), 'E_B2L1' => $existencia($b2, $l1), 'E_B2L3' => $existencia($b2, $l3),
        ],
    ];
}

describe('RN-06-AC8 consulta del kardex', function () {
    it('RN-06-AC8 S-36: filtros producto_id, lote_id, bodega_id, existencia_id, desde y hasta combinables → 200 con exactamente los movimientos que cumplen, en orden created_at, id', function (array $filtros, array $esperados) {
        $escenario = rn06EscenarioKardex();
        olvidarAutenticacion();
        actuarComo('auditor');

        $query = [];
        foreach ($filtros as $campo => $valor) {
            $query[$campo] = str_ends_with($campo, '_id') ? $escenario['ent'][$valor] : $valor;
        }

        $r = $this->getJson(RN06_URI_KARDEX.'?'.http_build_query($query));

        $r->assertOk();
        expect(rn06IdsRespuesta($r))->toBe(array_map(fn (string $m): int => $escenario['ids'][$m], $esperados))
            ->and($r->json('meta.total'))->toBe(count($esperados));
    })->with([
        'sin filtros' => [[], ['m1', 'm2', 'm3', 'm4', 'm5', 'm6', 'm7']],
        'producto P1' => [['producto_id' => 'P1'], ['m1', 'm2', 'm4', 'm5']],
        'producto P2' => [['producto_id' => 'P2'], ['m3', 'm6', 'm7']],
        'lote L1' => [['lote_id' => 'L1'], ['m1', 'm4', 'm5']],
        'lote L2' => [['lote_id' => 'L2'], ['m2']],
        'bodega B1' => [['bodega_id' => 'B1'], ['m1', 'm2', 'm3', 'm5']],
        'bodega B2 + producto P1' => [['bodega_id' => 'B2', 'producto_id' => 'P1'], ['m4']],
        'bodega B1 + lote L1' => [['bodega_id' => 'B1', 'lote_id' => 'L1'], ['m1', 'm5']],
        'producto P2 + lote L1 (contradictorio)' => [['producto_id' => 'P2', 'lote_id' => 'L1'], []],
        'desde d-2' => [['desde' => '2026-11-02'], ['m2', 'm3', 'm4', 'm5', 'm6', 'm7']],
        'hasta d-2' => [['hasta' => '2026-11-02'], ['m1', 'm2', 'm3']],
        'desde = hasta = d-1' => [['desde' => '2026-11-03', 'hasta' => '2026-11-03'], ['m4', 'm5']],
        'desde = hasta = hoy' => [['desde' => RN06_HOY, 'hasta' => RN06_HOY], ['m6', 'm7']],
        'desde d-1 + bodega B2' => [['desde' => '2026-11-03', 'bodega_id' => 'B2'], ['m4', 'm6', 'm7']],
        'rango d-2..d-1 + producto P1' => [['desde' => '2026-11-02', 'hasta' => '2026-11-03', 'producto_id' => 'P1'], ['m2', 'm4', 'm5']],
        'todos los filtros a la vez' => [['producto_id' => 'P1', 'lote_id' => 'L1', 'bodega_id' => 'B1', 'desde' => '2026-11-03', 'hasta' => RN06_HOY], ['m5']],
        'rango sin movimientos' => [['desde' => '2026-10-01', 'hasta' => '2026-10-31'], []],
        'rango futuro' => [['desde' => '2026-11-05'], []],
        'existencia B1·L1' => [['existencia_id' => 'E_B1L1'], ['m1', 'm5']],
        'existencia B2·L1 (mismo lote en otra bodega)' => [['existencia_id' => 'E_B2L1'], ['m4']],
        'existencia B2·L3 + desde hoy' => [['existencia_id' => 'E_B2L3', 'desde' => RN06_HOY], ['m6', 'm7']],
        'existencia B1·L1 + hasta d-2' => [['existencia_id' => 'E_B1L1', 'hasta' => '2026-11-02'], ['m1']],
        'existencia B1·L1 + producto P1 + lote L1 + bodega B1' => [['existencia_id' => 'E_B1L1', 'producto_id' => 'P1', 'lote_id' => 'L1', 'bodega_id' => 'B1'], ['m1', 'm5']],
        'existencia B1·L1 + bodega B2 (contradictorio)' => [['existencia_id' => 'E_B1L1', 'bodega_id' => 'B2'], []],
    ]);

    it('RN-06-AC8: cada elemento trae id, tipo, cantidad con signo, saldos, motivo y created_at', function () {
        $escenario = rn06EscenarioKardex();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN06_URI_KARDEX.'?'.http_build_query(['lote_id' => $escenario['ent']['L1']]));

        $r->assertOk();
        $data = $r->json('data');
        expect($data)->toHaveCount(3)
            ->and($data[2])->toHaveKeys(['id', 'tipo', 'cantidad', 'saldo_anterior', 'saldo_posterior', 'motivo', 'created_at'])
            ->and($data[2]['id'])->toBe($escenario['ids']['m5'])
            ->and($data[2]['tipo'])->toBe('AJUSTE')
            ->and($data[2]['cantidad'])->toBe(-4)
            ->and($data[2]['saldo_anterior'])->toBe(10)
            ->and($data[2]['saldo_posterior'])->toBe(6)
            ->and($data[2]['motivo'])->toBe(RN06_MOTIVO)
            ->and($data[0]['tipo'])->toBe('ENTRADA')
            ->and($data[0]['cantidad'])->toBe(10);
    });

    it('RN-06-AC8 S-36 zona horaria: desde/hasta son días de Bogotá inclusivos (00:00:00 y 23:59:59 entran; 23:59:59 del día anterior y 00:00:00 del siguiente no)', function (array $filtros, array $esperados) {
        $bodega = Bodega::factory()->create();
        $lote = rn06LoteVigente();
        $ids = [];
        foreach (['antes' => '2026-11-03 23:59:59', 'inicio' => '2026-11-04 00:00:00', 'fin' => '2026-11-04 23:59:59', 'despues' => '2026-11-05 00:00:00'] as $clave => $instante) {
            rn06Congelar($instante);
            rn06Entrada($bodega, $lote, 1)->assertCreated();
            $ids[$clave] = (int) DB::table('movimientos_kardex')->max('id');
        }
        actuarComo('auxiliar_farmacia');

        $r = $this->getJson(RN06_URI_KARDEX.'?'.http_build_query($filtros));

        $r->assertOk();
        expect(rn06IdsRespuesta($r))->toBe(array_map(fn (string $c): int => $ids[$c], $esperados));
    })->with([
        'desde = hasta = 2026-11-04' => [['desde' => '2026-11-04', 'hasta' => '2026-11-04'], ['inicio', 'fin']],
        'desde 2026-11-04' => [['desde' => '2026-11-04'], ['inicio', 'fin', 'despues']],
        'hasta 2026-11-04' => [['hasta' => '2026-11-04'], ['antes', 'inicio', 'fin']],
        'desde = hasta = 2026-11-05' => [['desde' => '2026-11-05', 'hasta' => '2026-11-05'], ['despues']],
        'hasta 2026-11-03' => [['hasta' => '2026-11-03'], ['antes']],
    ]);

    it('RN-06-AC8: el orden es created_at y luego id, aunque los ids no sigan el orden temporal', function () {
        rn06Congelar();
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $usuario = usuarioConRol('regente_farmacia');
        $base = now();
        // Insertados en desorden temporal: el id NO refleja el created_at.
        $orden = [
            'c' => $base->copy()->subHours(1),
            'a' => $base->copy()->subHours(3),
            'b1' => $base->copy()->subHours(2),
            'b2' => $base->copy()->subHours(2), // empate de created_at con b1: desempata el id
            'd' => $base->copy(),
        ];
        $ids = [];
        $saldo = 0;
        foreach ($orden as $clave => $instante) {
            $ids[$clave] = DB::table('movimientos_kardex')->insertGetId(rn06FilaMovimiento($existencia, $usuario, [
                'cantidad' => 1, 'saldo_anterior' => $saldo, 'saldo_posterior' => $saldo + 1, 'created_at' => $instante,
            ]));
            $saldo++;
        }
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN06_URI_KARDEX);

        $r->assertOk();
        expect(rn06IdsRespuesta($r))->toBe([$ids['a'], $ids['b1'], $ids['b2'], $ids['c'], $ids['d']]);
    });

    it('RN-06-AC8 paginación: 25 por defecto, páginas sin solapes que respetan el orden global, por_pagina hasta 100', function () {
        rn06Congelar();
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $usuario = usuarioConRol('regente_farmacia');
        $esperados = [];
        foreach (range(0, 29) as $i) {
            $esperados[] = DB::table('movimientos_kardex')->insertGetId(rn06FilaMovimiento($existencia, $usuario, [
                'cantidad' => 1, 'saldo_anterior' => $i, 'saldo_posterior' => $i + 1, 'created_at' => now()->subMinutes(30 - $i),
            ]));
        }
        actuarComo('auditor');

        $p1 = $this->getJson(RN06_URI_KARDEX);
        $p2 = $this->getJson(RN06_URI_KARDEX.'?page=2');
        $p3 = $this->getJson(RN06_URI_KARDEX.'?page=3');
        $p10 = $this->getJson(RN06_URI_KARDEX.'?por_pagina=10&page=3');
        $p100 = $this->getJson(RN06_URI_KARDEX.'?por_pagina=100');

        foreach ([$p1, $p2, $p3, $p10, $p100] as $r) {
            $r->assertOk();
        }
        expect(rn06IdsRespuesta($p1))->toHaveCount(25)
            ->and([...rn06IdsRespuesta($p1), ...rn06IdsRespuesta($p2)])->toBe($esperados)
            ->and(rn06IdsRespuesta($p3))->toBe([])
            ->and($p1->json('meta.per_page'))->toBe(25)
            ->and($p1->json('meta.total'))->toBe(30)
            ->and(rn06IdsRespuesta($p10))->toBe(array_slice($esperados, 20, 10))
            ->and(rn06IdsRespuesta($p100))->toBe($esperados);
    });

    it('RN-06-AC8: un filtro con id bien formado pero inexistente → 200 con data vacío', function (string $campo) {
        rn06EscenarioKardex();
        actuarComo('auditor');

        $r = $this->getJson(RN06_URI_KARDEX.'?'.http_build_query([$campo => 987654321]));

        $r->assertOk();
        expect($r->json('data'))->toBe([]);
    })->with(['producto_id', 'lote_id', 'bodega_id', 'existencia_id']);

    it('RN-06-AC8 S-36: filtros mal formados → 422 VALIDACION con el campo en detalles.campos (nunca 500)', function (string $campo, string $valor) {
        rn06EscenarioKardex();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN06_URI_KARDEX.'?'.http_build_query([$campo => $valor]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo);
    })->with([
        'producto_id texto' => ['producto_id', 'abc'],
        'producto_id cero' => ['producto_id', '0'],
        'producto_id negativo' => ['producto_id', '-1'],
        'lote_id decimal' => ['lote_id', '1.5'],
        'lote_id desmesurado' => ['lote_id', '99999999999999999999'],
        'bodega_id texto' => ['bodega_id', 'central'],
        'existencia_id texto' => ['existencia_id', 'abc'],
        'existencia_id cero' => ['existencia_id', '0'],
        'existencia_id negativo' => ['existencia_id', '-1'],
        'existencia_id decimal' => ['existencia_id', '1.5'],
        'existencia_id desmesurado' => ['existencia_id', '99999999999999999999'],
        'desde mes 13' => ['desde', '2026-13-01'],
        'desde formato dd/mm/aaaa' => ['desde', '04/11/2026'],
        'desde texto' => ['desde', 'ayer'],
        'hasta día 31 de noviembre' => ['hasta', '2026-11-31'],
        'hasta año de 5 dígitos' => ['hasta', '20260-11-04'],
        'por_pagina 101' => ['por_pagina', '101'],
        'por_pagina 0' => ['por_pagina', '0'],
        'page 0' => ['page', '0'],
        'page desmesurado' => ['page', '99999999999'],
    ]);

    it('RN-06-AC8 S-36: hasta anterior a desde → 422 VALIDACION (nunca 500 ni lista silenciosamente vacía)', function () {
        rn06EscenarioKardex();
        actuarComo('regente_farmacia');

        $r = $this->getJson(RN06_URI_KARDEX.'?desde=2026-11-04&hasta=2026-11-03');

        esperarError($r, 422, 'VALIDACION');
        expect(array_keys($r->json('error.detalles.campos')))->toContain('hasta');
    });

    it('RN-06-AC8: la consulta no modifica inventario ni kardex', function () {
        rn06EscenarioKardex();
        $antes = rn06Instantanea();
        actuarComo('auditor');

        $this->getJson(RN06_URI_KARDEX)->assertOk();

        expect(rn06Instantanea())->toBe($antes);
    });

    it('RN-06-AC8 S-37 roles: auxiliar, regente y auditor consultan (200); médico y admin → 403 NO_AUTORIZADO', function (string $rol, bool $permitido) {
        rn06EscenarioKardex();
        olvidarAutenticacion();
        actuarComo($rol);

        $r = $this->getJson(RN06_URI_KARDEX);

        if ($permitido) {
            $r->assertOk();
            expect($r->json('data'))->toHaveCount(7);
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

    it('RN-06-AC8 S-37 detalle: GET /kardex/{id} → 200 con el recurso para auxiliar, regente y auditor; 403 NO_AUTORIZADO para médico y admin', function (string $rol, bool $permitido) {
        $escenario = rn06EscenarioKardex();
        $id = $escenario['ids']['m5'];
        $fila = DB::table('movimientos_kardex')->where('id', $id)->sole();
        $antes = rn06Instantanea();
        olvidarAutenticacion();
        actuarComo($rol);

        $r = $this->getJson(RN06_URI_KARDEX.'/'.$id);

        if ($permitido) {
            $r->assertOk();
            $data = cuerpoDatos($r);
            expect($data)->toHaveKeys(['id', 'tipo', 'existencia_id', 'bodega_id', 'producto_id', 'lote_id', 'cantidad', 'saldo_anterior', 'saldo_posterior', 'motivo', 'usuario_id', 'documento', 'created_at'])
                ->and($data['id'])->toBe($id)
                ->and($data['tipo'])->toBe('AJUSTE')
                ->and($data['existencia_id'])->toBe((int) $fila->existencia_id)
                ->and($data['bodega_id'])->toBe($escenario['ent']['B1'])
                ->and($data['lote_id'])->toBe($escenario['ent']['L1'])
                ->and($data['producto_id'])->toBe($escenario['ent']['P1'])
                ->and($data['cantidad'])->toBe(-4)
                ->and($data['saldo_anterior'])->toBe(10)
                ->and($data['saldo_posterior'])->toBe(6)
                ->and($data['motivo'])->toBe(RN06_MOTIVO)
                ->and($data['usuario_id'])->toBe((int) $fila->usuario_id)
                ->and($data['documento'])->toBeNull();
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
        }
        expect(rn06Instantanea())->toBe($antes);
    })->with([
        'auxiliar_farmacia' => ['auxiliar_farmacia', true],
        'regente_farmacia' => ['regente_farmacia', true],
        'auditor' => ['auditor', true],
        'medico' => ['medico', false],
        'admin (S-13)' => ['admin', false],
    ]);

    it('RN-06-AC8 S-37 detalle: GET /kardex/{id} con un id inexistente o no numérico → 404 NO_ENCONTRADO', function (string $id) {
        rn06EscenarioKardex();
        olvidarAutenticacion();
        actuarComo('auditor');

        esperarError($this->getJson(RN06_URI_KARDEX.'/'.$id), 404, 'NO_ENCONTRADO');
    })->with([
        'inexistente' => ['987654321'],
        'no numérico' => ['abc'],
        'decimal' => ['1.5'],
        'negativo' => ['-1'],
    ]);

    it('RN-06-AC8 S-37 roles: sin token → 401 NO_AUTENTICADO', function () {
        rn06Congelar();
        olvidarAutenticacion();

        esperarError($this->getJson(RN06_URI_KARDEX), 401, 'NO_AUTENTICADO');
    });
});
