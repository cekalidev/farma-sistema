<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Http\Requests\Dispensacion\StoreDispensacionRequest;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * RN-02 — FEFO, puede tomar varios lotes (AC1..AC6) y RN-01-AC5 (aplazado desde RN-01: solo
 * stock vencido → 409 STOCK_INSUFICIENTE). Supuestos: S-01 (vence hoy = vencido, hoy en Bogotá),
 * S-02 (409 aunque haya vencido; detalles.vencido_no_dispensable), S-03 (empate → menor lotes.id),
 * S-04 (todo o nada), S-07 (enteros positivos), S-35 (SALIDA_DISPENSACION → documento
 * 'dispensacion' + id).
 *
 * Contrato HTTP asumido (los nombres se concentran en los ayudantes rn02Cuerpo / rn02Asignacion):
 *  - POST /api/v1/dispensaciones   {paciente_id, prescripcion_id, bodega_id,
 *                                     items: [{prescripcion_item_id, cantidad}, …]}
 *      201 → data {id, bodega_id, detalles: [{producto_id, lote_id, cantidad, …}, …]} (orden FEFO)
 *  - POST /api/v1/dispensaciones/previsualizar  mismo cuerpo
 *      200 → data {bodega_id, orientativa, items: [{prescripcion_item_id, producto_id, cantidad, …}],
 *                  detalles: [{prescripcion_item_id, producto_id, lote_id, cantidad, …}]} sin escribir nada
 *  - Roles: auxiliar_farmacia y regente_farmacia; el resto 403 NO_AUTORIZADO; sin token 401.
 *  - Tablas: dispensaciones (id, bodega_id), dispensacion_items, dispensacion_detalles
 *    (dispensacion_id, producto_id, lote_id, cantidad); kardex SALIDA_DISPENSACION con cantidad negativa.
 *
 * El stock se crea por el camino real (RegistradorKardex, ENTRADA dentro de transacción con la
 * existencia bloqueada) para que el kardex cuadre desde 0 (RN-06-AC2). Los lotes vencidos también
 * entran así (como la semilla, S-38): la API de entradas los rechazaría (RN-01-AC8).
 */

const RN02_URI = '/api/v1/dispensaciones';
const RN02_URI_PREVIA = '/api/v1/dispensaciones/previsualizar';
const RN02_HOY = '2026-11-04';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn02)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en una hora local de Bogotá (por defecto 2026-11-04 10:00). */
function rn02Congelar(string $fechaHoraBogota = RN02_HOY.' 10:00:00'): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Fecha Y-m-d a N días del "hoy" congelado. */
function rn02Dia(int $dias): string
{
    return Carbon::parse(RN02_HOY, 'America/Bogota')->addDays($dias)->toDateString();
}

/** Lote del producto con fecha de vencimiento exacta. */
function rn02Lote(Producto $producto, string $fechaVencimiento, ?string $codigo = null): Lote
{
    $atributos = ['producto_id' => $producto->id, 'fecha_vencimiento' => $fechaVencimiento];
    if ($codigo !== null) {
        $atributos['codigo'] = $codigo;
    }

    return Lote::factory()->create($atributos);
}

/**
 * Existencia (bodega, lote) que nace en 0 y recibe `cantidad` con una ENTRADA real del kardex.
 * Con cantidad 0 solo crea la existencia vacía (sin movimiento).
 */
function rn02Stock(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $regente = User::query()->where('email', 'rn02-regente-servicio@farma.test')->first()
            ?? usuarioConRol('regente_farmacia', ['email' => 'rn02-regente-servicio@farma.test']);

        DB::transaction(function () use ($existencia, $cantidad, $regente): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
        });
    }

    return $existencia->refresh();
}

/** Lote nuevo del producto con fecha y stock en la bodega. */
function rn02LoteConStock(Bodega $bodega, Producto $producto, string $fechaVencimiento, int $cantidad, ?string $codigo = null): Existencia
{
    return rn02Stock($bodega, rn02Lote($producto, $fechaVencimiento, $codigo), $cantidad);
}

/**
 * Cuerpo de la petición. La dispensación se hace contra una prescripción (forma de RN-04): por
 * cada producto se crea una prescripción vigente con un ítem holgado (1000 u.) para que solo FEFO
 * y el stock decidan. El producto lo fija el ítem prescrito.
 *
 * @param  list<array{0: int, 1: mixed}>  $items  [[producto_id, cantidad], …] (productos distintos, S-20)
 * @return array<string, mixed>
 */
function rn02Cuerpo(int $bodegaId, array $items): array
{
    $prescripcion = Prescripcion::factory()->create();

    return [
        'paciente_id' => $prescripcion->paciente_id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => $bodegaId,
        'items' => array_map(fn (array $i): array => [
            'prescripcion_item_id' => PrescripcionItem::factory()->create([
                'prescripcion_id' => $prescripcion->id,
                'producto_id' => $i[0],
                'cantidad' => 1000,
            ])->id,
            'cantidad' => $i[1],
        ], $items),
    ];
}

/**
 * Una Idempotency-Key nueva por petición (RN-09; la previsualización la ignora).
 *
 * @param  array<string, mixed>  $cuerpo
 */
function rn02Post(string $uri, array $cuerpo): TestResponse
{
    return test()->postJson($uri, $cuerpo, cabecerasDispensacion(), JSON_PRESERVE_ZERO_FRACTION);
}

/** Dispensa un solo producto. */
function rn02Dispensar(Bodega $bodega, Producto $producto, mixed $cantidad): TestResponse
{
    return rn02Post(RN02_URI, rn02Cuerpo($bodega->id, [[$producto->id, $cantidad]]));
}

/** Previsualiza un solo producto. */
function rn02Previsualizar(Bodega $bodega, Producto $producto, mixed $cantidad): TestResponse
{
    return rn02Post(RN02_URI_PREVIA, rn02Cuerpo($bodega->id, [[$producto->id, $cantidad]]));
}

/**
 * Asignación por lote que devuelve la API, en el orden recibido. Dispensación (201) y
 * previsualización (200) comparten el formato: data.detalles[] {producto_id, lote_id, cantidad, …}.
 *
 * @return list<array{producto_id: int, lote_id: int, cantidad: int}>
 */
function rn02Asignacion(TestResponse $respuesta): array
{
    $datos = cuerpoDatos($respuesta);
    expect($datos['detalles'] ?? null)->toBeArray('la respuesta no trae data.detalles');

    return array_values(array_map(fn (array $d): array => [
        'producto_id' => $d['producto_id'] ?? null,
        'lote_id' => $d['lote_id'] ?? null,
        'cantidad' => $d['cantidad'] ?? null,
    ], $datos['detalles']));
}

/**
 * Asignación esperada a partir de existencias y cantidades, en orden.
 *
 * @param  list<array{0: Existencia, 1: int}>  $pares
 * @return list<array{producto_id: int, lote_id: int, cantidad: int}>
 */
function rn02Esperado(array $pares): array
{
    return array_map(fn (array $p): array => [
        'producto_id' => $p[0]->producto_id,
        'lote_id' => $p[0]->lote_id,
        'cantidad' => $p[1],
    ], $pares);
}

/**
 * Detalles guardados de una dispensación, en orden de inserción.
 *
 * @return list<array{producto_id: int, lote_id: int, cantidad: int}>
 */
function rn02DetallesBd(int $dispensacionId): array
{
    return DB::table('dispensacion_detalles')->where('dispensacion_id', $dispensacionId)->orderBy('id')
        ->get(['producto_id', 'lote_id', 'cantidad'])
        ->map(fn (object $f): array => [
            'producto_id' => (int) $f->producto_id,
            'lote_id' => (int) $f->lote_id,
            'cantidad' => (int) $f->cantidad,
        ])->all();
}

/** Movimientos SALIDA_DISPENSACION (filas crudas) en orden de id. @return list<object> */
function rn02Salidas(?int $dispensacionId = null): array
{
    return DB::table('movimientos_kardex')->where('tipo', 'SALIDA_DISPENSACION')
        ->when($dispensacionId !== null, fn ($q) => $q->where('documento_id', $dispensacionId))
        ->orderBy('id')->get()->all();
}

function rn02StockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Estado completo: en un rechazo o en una previsualización debe quedar idéntico.
 *
 * @return array<string, mixed>
 */
function rn02Instantanea(): array
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

/**
 * RN-06-AC2 sobre la existencia: los saldos del kardex encadenan desde 0 y el último coincide con
 * existencias.cantidad.
 */
function rn02AfirmarCadena(Existencia $existencia): void
{
    $movimientos = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)
        ->orderBy('created_at')->orderBy('id')->get()->all();

    $saldo = 0;
    foreach ($movimientos as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existencia->id}, movimiento #{$i}: saldo_anterior no encadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }

    expect($saldo)->toBe(rn02StockDe($existencia), "existencia {$existencia->id}: el kardex no cuadra con el stock");
}

/**
 * Afirma el efecto completo de una dispensación creada (201):
 *  - una fila en dispensaciones con la bodega;
 *  - dispensacion_detalles = $esperado, en ese orden, y la respuesta trae lo mismo;
 *  - un SALIDA_DISPENSACION por detalle, en el mismo orden, con cantidad negativa, saldos,
 *    existencia/bodega/producto/lote, usuario y documento 'dispensacion' + id (S-35);
 *  - la cadena de saldos de cada existencia tocada cuadra.
 *
 * @param  list<array{0: Existencia, 1: int}>  $pares  [existencia, cantidad tomada] en orden FEFO
 */
function rn02AfirmarDispensacion(TestResponse $r, User $usuario, Bodega $bodega, array $pares): int
{
    $r->assertCreated();
    $id = cuerpoDatos($r)['id'] ?? null;
    expect($id)->toBeInt();

    $esperado = rn02Esperado($pares);

    expect(DB::table('dispensaciones')->where('id', $id)->value('bodega_id'))->toBe($bodega->id)
        ->and(rn02DetallesBd($id))->toBe($esperado)
        ->and(rn02Asignacion($r))->toBe($esperado);

    $salidas = rn02Salidas($id);
    expect($salidas)->toHaveCount(count($pares));

    foreach ($pares as $i => [$existencia, $cantidad]) {
        $m = $salidas[$i];
        $stock = rn02StockDe($existencia);
        expect($m->tipo)->toBe('SALIDA_DISPENSACION')
            ->and((int) $m->existencia_id)->toBe($existencia->id, "salida #{$i}: existencia distinta (orden FEFO)")
            ->and((int) $m->bodega_id)->toBe($bodega->id)
            ->and((int) $m->producto_id)->toBe($existencia->producto_id)
            ->and((int) $m->lote_id)->toBe($existencia->lote_id)
            ->and((int) $m->cantidad)->toBe(-$cantidad)
            ->and((int) $m->saldo_posterior)->toBe($stock)
            ->and((int) $m->saldo_anterior)->toBe($stock + $cantidad)
            ->and((int) $m->usuario_id)->toBe($usuario->id)
            ->and($m->documento_tipo)->toBe('dispensacion')
            ->and((int) $m->documento_id)->toBe($id)
            ->and($m->motivo)->toBeNull();
        rn02AfirmarCadena($existencia);
    }

    return $id;
}

/** 409 STOCK_INSUFICIENTE con los detalles de S-02 / RN-03-AC2. */
function rn02AfirmarInsuficiente(TestResponse $r, int $solicitado, int $disponible, int $vencido): void
{
    esperarError($r, 409, 'STOCK_INSUFICIENTE');
    expect($r->json('error.detalles.solicitado'))->toBe($solicitado)
        ->and($r->json('error.detalles.disponible'))->toBe($disponible)
        ->and($r->json('error.detalles.vencido_no_dispensable'))->toBe($vencido);
}

/**
 * Escenario de la spec (RN-02-AC1/AC2): A vence 2027-03-01 con 5 u. (creado primero, id menor) y
 * B vence 2026-12-15 con 3 u.
 *
 * @return array{bodega: Bodega, producto: Producto, a: Existencia, b: Existencia}
 */
function rn02EscenarioSpec(): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->create();
    $a = rn02LoteConStock($bodega, $producto, '2027-03-01', 5, 'LOTE-A');
    $b = rn02LoteConStock($bodega, $producto, '2026-12-15', 3, 'LOTE-B');

    return compact('bodega', 'producto', 'a', 'b');
}

/*
|--------------------------------------------------------------------------
| RN-02-AC1 / AC2 [OBLIGATORIOS]: escenario exacto de la spec
|--------------------------------------------------------------------------
*/

describe('RN-02 FEFO por HTTP: escenario de la spec', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC1: con A (2027-03-01, 5) y B (2026-12-15, 3), dispensar 2 toma 2 de B y A queda en 5', function () {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        $auxiliar = actuarComo('auxiliar_farmacia');
        $kardexA = DB::table('movimientos_kardex')->where('existencia_id', $a->id)->count();

        $r = rn02Dispensar($bodega, $producto, 2);

        rn02AfirmarDispensacion($r, $auxiliar, $bodega, [[$b, 2]]);
        expect(rn02StockDe($b))->toBe(1)
            ->and(rn02StockDe($a))->toBe(5)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $a->id)->count())->toBe($kardexA)
            ->and(DB::table('dispensaciones')->count())->toBe(1)
            ->and(DB::table('dispensacion_detalles')->count())->toBe(1)
            ->and(rn02Salidas())->toHaveCount(1);
    });

    it('RN-02-AC2: con el mismo stock, dispensar 5 toma 3 de B y 2 de A: dos dispensacion_detalles y dos SALIDA_DISPENSACION en ese orden', function () {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        $auxiliar = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 5);

        $id = rn02AfirmarDispensacion($r, $auxiliar, $bodega, [[$b, 3], [$a, 2]]);
        expect(rn02StockDe($b))->toBe(0)
            ->and(rn02StockDe($a))->toBe(3)
            ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->count())->toBe(2)
            ->and(array_map(fn (object $m): int => (int) $m->lote_id, rn02Salidas($id)))->toBe([$b->lote_id, $a->lote_id])
            ->and(rn02Salidas())->toHaveCount(2);
    });

    it('RN-02-AC2 cruce de lotes: la cantidad cruza 1, 2 y 3 lotes y agota exactamente todo el stock vigente', function (int $cantidad, array $esperadoPorCodigo) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        // Creados en orden contrario al FEFO: el id no debe decidir.
        $ex = [
            'L3' => rn02LoteConStock($bodega, $producto, '2027-06-01', 4, 'L3'),
            'L2' => rn02LoteConStock($bodega, $producto, '2027-01-15', 3, 'L2'),
            'L1' => rn02LoteConStock($bodega, $producto, '2026-11-20', 2, 'L1'),
        ];
        $iniciales = ['L1' => 2, 'L2' => 3, 'L3' => 4];
        $usuario = actuarComo('regente_farmacia');

        $r = rn02Dispensar($bodega, $producto, $cantidad);

        $pares = array_map(fn (string $codigo, int $c): array => [$ex[$codigo], $c], array_keys($esperadoPorCodigo), $esperadoPorCodigo);
        rn02AfirmarDispensacion($r, $usuario, $bodega, $pares);
        foreach ($iniciales as $codigo => $inicial) {
            expect(rn02StockDe($ex[$codigo]))->toBe($inicial - ($esperadoPorCodigo[$codigo] ?? 0), "stock de {$codigo}");
        }
    })->with([
        'cruza 1 lote (1)' => [1, ['L1' => 1]],
        'agota 1 lote exacto (2)' => [2, ['L1' => 2]],
        'cruza 2 lotes (3)' => [3, ['L1' => 2, 'L2' => 1]],
        'agota 2 lotes exactos (5)' => [5, ['L1' => 2, 'L2' => 3]],
        'cruza 3 lotes (6)' => [6, ['L1' => 2, 'L2' => 3, 'L3' => 1]],
        'suma exacta de todo el stock (9)' => [9, ['L1' => 2, 'L2' => 3, 'L3' => 4]],
    ]);

    it('RN-02-AC2 RN-03-AC2 S-04: stock + 1 → 409 STOCK_INSUFICIENTE con solicitado y disponible; no se crea nada y ambos lotes quedan intactos', function () {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, 9);

        rn02AfirmarInsuficiente($r, 9, 8, 0);
        expect(rn02Instantanea())->toBe($antes)
            ->and(rn02StockDe($a))->toBe(5)
            ->and(rn02StockDe($b))->toBe(3)
            ->and(DB::table('dispensaciones')->count())->toBe(0)
            ->and(rn02Salidas())->toBe([]);
    });

    it('RN-02-AC2 dispensaciones sucesivas: la segunda sigue el FEFO sobre el stock que dejó la primera', function () {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        $usuario = actuarComo('auxiliar_farmacia');

        rn02AfirmarDispensacion(rn02Dispensar($bodega, $producto, 2), $usuario, $bodega, [[$b, 2]]);
        rn02AfirmarDispensacion(rn02Dispensar($bodega, $producto, 3), $usuario, $bodega, [[$b, 1], [$a, 2]]);
        // B quedó en 0: la tercera solo toca A.
        rn02AfirmarDispensacion(rn02Dispensar($bodega, $producto, 3), $usuario, $bodega, [[$a, 3]]);

        expect(rn02StockDe($a))->toBe(0)
            ->and(rn02StockDe($b))->toBe(0)
            ->and(DB::table('dispensaciones')->count())->toBe(3)
            ->and(rn02Salidas())->toHaveCount(4);

        rn02AfirmarInsuficiente(rn02Dispensar($bodega, $producto, 1), 1, 0, 0);
    });

    it('RN-02-AC2 varios productos en una dispensación: cada ítem sigue su propio FEFO', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $p1Tarde = rn02LoteConStock($bodega, $p1, '2027-05-01', 10);
        $p1Pronto = rn02LoteConStock($bodega, $p1, '2026-12-01', 1);
        $p2Tarde = rn02LoteConStock($bodega, $p2, '2027-09-01', 10);
        $p2Pronto = rn02LoteConStock($bodega, $p2, '2027-02-01', 4);
        $usuario = actuarComo('regente_farmacia');

        $r = rn02Post(RN02_URI, rn02Cuerpo($bodega->id, [[$p1->id, 3], [$p2->id, 4]]));

        $r->assertCreated();
        $id = cuerpoDatos($r)['id'];
        $porProducto = fn (array $lista, int $productoId): array => array_values(array_filter($lista, fn (array $d): bool => $d['producto_id'] === $productoId));
        $detalles = rn02DetallesBd($id);

        expect($porProducto($detalles, $p1->id))->toBe(rn02Esperado([[$p1Pronto, 1], [$p1Tarde, 2]]))
            ->and($porProducto($detalles, $p2->id))->toBe(rn02Esperado([[$p2Pronto, 4]]))
            ->and($detalles)->toHaveCount(3)
            ->and(rn02Asignacion($r))->toBe($detalles)
            ->and(rn02StockDe($p1Pronto))->toBe(0)
            ->and(rn02StockDe($p1Tarde))->toBe(8)
            ->and(rn02StockDe($p2Pronto))->toBe(0)
            ->and(rn02StockDe($p2Tarde))->toBe(10);

        $salidas = rn02Salidas($id);
        expect($salidas)->toHaveCount(3)
            ->and(array_map(fn (object $m): array => ['producto_id' => (int) $m->producto_id, 'lote_id' => (int) $m->lote_id, 'cantidad' => -(int) $m->cantidad], $salidas))
            ->toBe($detalles);
        foreach ($salidas as $m) {
            expect((int) $m->usuario_id)->toBe($usuario->id)
                ->and($m->documento_tipo)->toBe('dispensacion');
        }
        foreach ([$p1Pronto, $p1Tarde, $p2Pronto] as $e) {
            rn02AfirmarCadena($e);
        }
    });

    it('RN-02-AC2 S-04 todo o nada con varios ítems: si un producto no alcanza, ninguno se descuenta (sin importar el orden de los ítems)', function (bool $fallaPrimero) {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        rn02LoteConStock($bodega, $p1, '2026-12-15', 3);
        rn02LoteConStock($bodega, $p1, '2027-03-01', 5);
        rn02LoteConStock($bodega, $p2, '2027-01-01', 2);
        rn02LoteConStock($bodega, $p2, '2027-02-01', 2);
        rn02LoteConStock($bodega, $p2, rn02Dia(0), 50); // vence hoy: no cuenta
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        // P1 cabe (6 de 8, cruza dos lotes); P2 pide 5 con 4 vigentes.
        $items = [[$p1->id, 6], [$p2->id, 5]];
        $r = rn02Post(RN02_URI, rn02Cuerpo($bodega->id, $fallaPrimero ? array_reverse($items) : $items));

        rn02AfirmarInsuficiente($r, 5, 4, 50);
        expect($r->json('error.detalles.producto_id'))->toBe($p2->id)
            ->and(rn02Instantanea())->toBe($antes);
    })->with([
        'el ítem que falla va segundo' => [false],
        'el ítem que falla va primero' => [true],
    ]);
});

/*
|--------------------------------------------------------------------------
| RN-02-AC3 [OBLIGATORIO] + RN-01-AC5: lotes vencidos y que vencen hoy
|--------------------------------------------------------------------------
*/

describe('RN-02-AC3 / RN-01-AC5 lotes vencidos', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC3: con un lote vencido, uno que vence hoy y uno vigente, solo se toma del vigente', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $vencido = rn02LoteConStock($bodega, $producto, '2026-10-01', 10);
        $hoy = rn02LoteConStock($bodega, $producto, RN02_HOY, 10);
        $vigente = rn02LoteConStock($bodega, $producto, '2027-01-10', 10);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 4);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$vigente, 4]]);
        expect(rn02StockDe($vencido))->toBe(10)
            ->and(rn02StockDe($hoy))->toBe(10)
            ->and(rn02StockDe($vigente))->toBe(6)
            ->and(rn02Salidas())->toHaveCount(1);
    });

    it('RN-02-AC3 frontera: vence ayer y vence hoy se ignoran; vence mañana es el primero en salir', function (int $dias, bool $seToma) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $frontera = rn02LoteConStock($bodega, $producto, rn02Dia($dias), 5);
        $vigente = rn02LoteConStock($bodega, $producto, rn02Dia(200), 5);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 3);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$seToma ? $frontera : $vigente, 3]]);
        expect(rn02StockDe($frontera))->toBe($seToma ? 2 : 5)
            ->and(rn02StockDe($vigente))->toBe($seToma ? 5 : 2);
    })->with([
        'vence ayer' => [-1, false],
        'vence hoy (S-01: vencido)' => [0, false],
        'vence mañana' => [1, true],
    ]);

    it('RN-02-AC3 lote vencido intercalado: cruzar dos vigentes salta el vencido que queda entre ellos en fecha', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $vencido = rn02LoteConStock($bodega, $producto, '2026-06-30', 40);
        $v1 = rn02LoteConStock($bodega, $producto, '2026-11-05', 2); // vence mañana
        $hoy = rn02LoteConStock($bodega, $producto, RN02_HOY, 40);
        $v2 = rn02LoteConStock($bodega, $producto, '2027-02-01', 6);
        $usuario = actuarComo('regente_farmacia');

        $r = rn02Dispensar($bodega, $producto, 5);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$v1, 2], [$v2, 3]]);
        expect(rn02StockDe($vencido))->toBe(40)
            ->and(rn02StockDe($hoy))->toBe(40);
    });

    it('RN-02-AC3 S-01 zona horaria: el día que cuenta es el de Bogotá, no el de UTC', function (string $instanteUtc, bool $seTomaElDel05) {
        $this->travelTo(Carbon::parse($instanteUtc, 'UTC'));
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $del04 = rn02LoteConStock($bodega, $producto, '2026-11-04', 5);
        $del05 = rn02LoteConStock($bodega, $producto, '2026-11-05', 5);
        $lejano = rn02LoteConStock($bodega, $producto, '2027-05-01', 5);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 1);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$seTomaElDel05 ? $del05 : $lejano, 1]]);
        expect(rn02StockDe($del04))->toBe(5);
    })->with([
        '04:30 UTC del 05 = 23:30 del 04 en Bogotá → el lote del 05 aún es vigente' => ['2026-11-05 04:30:00', true],
        '05:30 UTC del 05 = 00:30 del 05 en Bogotá → el lote del 05 vence hoy' => ['2026-11-05 05:30:00', false],
    ]);

    it('RN-02-AC3 S-02: si el vigente no alcanza → 409 STOCK_INSUFICIENTE aunque sobre stock vencido; nada cambia', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn02LoteConStock($bodega, $producto, '2026-10-01', 7);
        rn02LoteConStock($bodega, $producto, RN02_HOY, 3);
        rn02LoteConStock($bodega, $producto, '2027-01-10', 5);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, 6);

        rn02AfirmarInsuficiente($r, 6, 5, 10);
        expect(rn02Instantanea())->toBe($antes);
    });

    it('RN-01-AC5: solo stock de lotes vencidos (incluido uno que vence hoy) → 409 STOCK_INSUFICIENTE, disponible 0 y vencido_no_dispensable > 0; sin cambios', function (array $lotes, int $solicitado) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencias = [];
        foreach ($lotes as [$dias, $cantidad]) {
            $existencias[] = [rn02LoteConStock($bodega, $producto, rn02Dia($dias), $cantidad), $cantidad];
        }
        $vencido = array_sum(array_column($lotes, 1));
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();
        $kardexAntes = DB::table('movimientos_kardex')->count();

        $r = rn02Dispensar($bodega, $producto, $solicitado);

        rn02AfirmarInsuficiente($r, $solicitado, 0, $vencido);
        expect($r->json('error.detalles.vencido_no_dispensable'))->toBeGreaterThan(0)
            ->and(rn02Instantanea())->toBe($antes)
            ->and(DB::table('dispensaciones')->count())->toBe(0)
            ->and(DB::table('dispensacion_detalles')->count())->toBe(0)
            ->and(DB::table('movimientos_kardex')->count())->toBe($kardexAntes)
            ->and(rn02Salidas())->toBe([]);
        foreach ($existencias as [$existencia, $cantidad]) {
            expect(rn02StockDe($existencia))->toBe($cantidad);
        }
    })->with([
        'solo uno que vence hoy, se pide 1' => [[[0, 6]], 1],
        'vencido + vence hoy, se pide 1' => [[[-30, 4], [0, 6]], 1],
        'vencido ayer + vence hoy, se pide menos que lo vencido' => [[[-1, 8], [0, 2]], 5],
        'varios vencidos y uno de hoy, se pide más que todo' => [[[-365, 3], [-2, 3], [0, 3]], 20],
    ]);

    it('RN-01-AC5 la previsualización tampoco ofrece lotes vencidos: 409 STOCK_INSUFICIENTE con disponible 0 y sin cambios', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn02LoteConStock($bodega, $producto, rn02Dia(-10), 4);
        rn02LoteConStock($bodega, $producto, rn02Dia(0), 5);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Previsualizar($bodega, $producto, 1);

        rn02AfirmarInsuficiente($r, 1, 0, 9);
        expect(rn02Instantanea())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| RN-02-AC4 [OBLIGATORIO]: empate de fechas → menor lotes.id (S-03)
|--------------------------------------------------------------------------
*/

describe('RN-02-AC4 empate de fecha de vencimiento', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC4: con dos lotes de la misma fecha, dispensar menos que la suma consume primero el de menor lotes.id', function (int $cantidad, int $delMenor, int $delMayor) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        // El de menor id se registra antes, pero su código ordena después y su existencia se crea
        // después: ni el código ni existencias.id deben decidir el desempate.
        $loteMenor = rn02Lote($producto, '2027-01-10', 'ZZZ-PRIMERO');
        $loteMayor = rn02Lote($producto, '2027-01-10', 'AAA-SEGUNDO');
        expect($loteMenor->id)->toBeLessThan($loteMayor->id);
        $exMayor = rn02Stock($bodega, $loteMayor, 4);
        $exMenor = rn02Stock($bodega, $loteMenor, 4);
        expect($exMayor->id)->toBeLessThan($exMenor->id);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, $cantidad);

        $pares = array_values(array_filter([[$exMenor, $delMenor], [$exMayor, $delMayor]], fn (array $p): bool => $p[1] > 0));
        rn02AfirmarDispensacion($r, $usuario, $bodega, $pares);
        expect(rn02StockDe($exMenor))->toBe(4 - $delMenor)
            ->and(rn02StockDe($exMayor))->toBe(4 - $delMayor);
    })->with([
        'menos que el primero (1)' => [1, 1, 0],
        'exactamente el primero (4)' => [4, 4, 0],
        'menos que la suma, cruza al segundo (6)' => [6, 4, 2],
    ]);

    it('RN-02-AC4: la fecha manda antes que el id: un lote con id menor pero fecha posterior va después del empate', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $tardio = rn02LoteConStock($bodega, $producto, '2027-04-01', 5);   // id menor, vence después
        $empate1 = rn02LoteConStock($bodega, $producto, '2027-01-10', 1);
        $empate2 = rn02LoteConStock($bodega, $producto, '2027-01-10', 1);
        $usuario = actuarComo('regente_farmacia');

        $r = rn02Dispensar($bodega, $producto, 3);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$empate1, 1], [$empate2, 1], [$tardio, 1]]);
    });
});

/*
|--------------------------------------------------------------------------
| RN-02-AC5: otra bodega y existencias en 0 se ignoran
|--------------------------------------------------------------------------
*/

describe('RN-02-AC5 existencias ajenas o vacías', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC5: el stock del mismo producto en otra bodega se ignora aunque venza antes', function () {
        $bodega = Bodega::factory()->create();
        $otra = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $loteCompartido = rn02Lote($producto, '2026-11-20'); // el que vence antes, pero solo en otra bodega
        $ajena = rn02Stock($otra, $loteCompartido, 50);
        $propia = rn02LoteConStock($bodega, $producto, '2027-03-01', 5);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 2);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$propia, 2]]);
        expect(rn02StockDe($ajena))->toBe(50)
            ->and(DB::table('movimientos_kardex')->where('bodega_id', $otra->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(0);
    });

    it('RN-02-AC5: una existencia en 0 (aunque sea la que vence antes) se ignora y no genera detalle ni kardex', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $vacia = rn02LoteConStock($bodega, $producto, '2026-11-10', 0);
        $b = rn02LoteConStock($bodega, $producto, '2026-12-15', 3);
        $a = rn02LoteConStock($bodega, $producto, '2027-03-01', 5);
        $usuario = actuarComo('auxiliar_farmacia');

        $r = rn02Dispensar($bodega, $producto, 4);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$b, 3], [$a, 1]]);
        expect(rn02StockDe($vacia))->toBe(0)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $vacia->id)->count())->toBe(0)
            ->and(DB::table('dispensacion_detalles')->where('lote_id', $vacia->lote_id)->count())->toBe(0);
    });

    it('RN-02-AC5: el stock de otra bodega no cuenta como disponible ni como vencido: 409 con lo de esta bodega y sin tocar la otra', function () {
        $bodega = Bodega::factory()->create();
        $otra = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn02LoteConStock($bodega, $producto, '2027-01-01', 3);
        rn02LoteConStock($bodega, $producto, rn02Dia(-5), 2);
        rn02LoteConStock($otra, $producto, '2026-12-01', 100);
        rn02LoteConStock($otra, $producto, rn02Dia(-5), 70);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, 4);

        rn02AfirmarInsuficiente($r, 4, 3, 2);
        expect(rn02Instantanea())->toBe($antes);
    });

    it('RN-02-AC5: un producto sin ninguna existencia en la bodega (solo en otra, o en 0) → 409 con disponible 0 y sin cambios', function (string $caso) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        match ($caso) {
            'solo en otra bodega' => rn02LoteConStock(Bodega::factory()->create(), $producto, '2027-01-01', 30),
            'existencias en 0' => rn02LoteConStock($bodega, $producto, '2027-01-01', 0),
            'sin existencias' => null,
        };
        // Otro producto con stock en la misma bodega: no debe usarse.
        $otroProducto = rn02LoteConStock($bodega, Producto::factory()->create(), '2027-01-01', 30);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, 1);

        rn02AfirmarInsuficiente($r, 1, 0, 0);
        expect(rn02Instantanea())->toBe($antes)
            ->and(rn02StockDe($otroProducto))->toBe(30);
    })->with([
        'solo en otra bodega' => ['solo en otra bodega'],
        'existencias en 0' => ['existencias en 0'],
        'sin existencias' => ['sin existencias'],
    ]);
});

/*
|--------------------------------------------------------------------------
| RN-02-AC6: previsualizar = misma asignación, sin escribir nada
|--------------------------------------------------------------------------
*/

describe('RN-02-AC6 previsualización', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC6: previsualizar 5 devuelve 200 con [B 3, A 2], sin cambios de stock, kardex ni dispensaciones; luego la dispensación hace lo mismo', function () {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        $usuario = actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $previa = rn02Previsualizar($bodega, $producto, 5);

        $previa->assertOk();
        $asignacion = rn02Asignacion($previa);
        expect($asignacion)->toBe(rn02Esperado([[$b, 3], [$a, 2]]))
            ->and(rn02Instantanea())->toBe($antes);

        $r = rn02Dispensar($bodega, $producto, 5);

        rn02AfirmarDispensacion($r, $usuario, $bodega, [[$b, 3], [$a, 2]]);
        expect(rn02Asignacion($r))->toBe($asignacion);
    });

    it('RN-02-AC6: para cualquier cantidad y mezcla de lotes la previsualización coincide con la dispensación posterior', function (int $cantidad) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        rn02LoteConStock($bodega, $producto, '2027-06-01', 4);
        rn02LoteConStock($bodega, $producto, rn02Dia(0), 9);           // vence hoy
        rn02LoteConStock($bodega, $producto, '2027-01-15', 3);
        rn02LoteConStock($bodega, $producto, '2027-01-15', 1);         // empata con el anterior
        rn02LoteConStock($bodega, $producto, '2026-11-20', 0);         // en 0
        rn02LoteConStock(Bodega::factory()->create(), $producto, '2026-11-06', 50); // otra bodega
        rn02LoteConStock($bodega, $producto, '2026-11-05', 2);         // vence mañana
        actuarComo('regente_farmacia');
        $antes = rn02Instantanea();

        $previa = rn02Previsualizar($bodega, $producto, $cantidad);
        $previa->assertOk();
        $asignacion = rn02Asignacion($previa);
        expect(rn02Instantanea())->toBe($antes)
            ->and(array_sum(array_column($asignacion, 'cantidad')))->toBe($cantidad);

        // Una segunda previsualización es idéntica (no reservó nada).
        expect(rn02Asignacion(rn02Previsualizar($bodega, $producto, $cantidad)))->toBe($asignacion)
            ->and(rn02Instantanea())->toBe($antes);

        $r = rn02Dispensar($bodega, $producto, $cantidad);

        $r->assertCreated();
        expect(rn02Asignacion($r))->toBe($asignacion)
            ->and(rn02DetallesBd(cuerpoDatos($r)['id']))->toBe($asignacion);
    })->with([
        'un lote (1)' => [1],
        'agota el que vence mañana (2)' => [2],
        'cruza al empate (3)' => [3],
        'cruza el empate completo (6)' => [6],
        'todo el vigente (10)' => [10],
    ]);

    it('RN-02-AC6 S-04: previsualizar más de lo vigente responde lo mismo que respondería la dispensación (409 STOCK_INSUFICIENTE) y nada cambia', function () {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        rn02LoteConStock($bodega, $producto, rn02Dia(0), 4); // vence hoy
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $cuerpo = rn02Cuerpo($bodega->id, [[$producto->id, 9]]); // mismo cuerpo para ambas
        $previa = rn02Post(RN02_URI_PREVIA, $cuerpo);
        $real = rn02Post(RN02_URI, $cuerpo);

        rn02AfirmarInsuficiente($previa, 9, 8, 4);
        rn02AfirmarInsuficiente($real, 9, 8, 4);
        expect($previa->json('error.detalles'))->toBe($real->json('error.detalles'))
            ->and(rn02Instantanea())->toBe($antes);
    });

    it('RN-02-AC6: previsualizar varios productos devuelve la asignación por lote de cada ítem sin escribir nada', function () {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $p1a = rn02LoteConStock($bodega, $p1, '2027-05-01', 10);
        $p1b = rn02LoteConStock($bodega, $p1, '2026-12-01', 1);
        $p2a = rn02LoteConStock($bodega, $p2, '2027-02-01', 4);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();
        $cuerpo = rn02Cuerpo($bodega->id, [[$p1->id, 3], [$p2->id, 2]]);

        $previa = rn02Post(RN02_URI_PREVIA, $cuerpo);

        $previa->assertOk();
        $asignacion = rn02Asignacion($previa);
        $ordenar = function (array $l): array {
            usort($l, fn (array $x, array $y): int => [$x['producto_id'], $x['lote_id']] <=> [$y['producto_id'], $y['lote_id']]);

            return $l;
        };
        expect($ordenar($asignacion))->toBe($ordenar(rn02Esperado([[$p1b, 1], [$p1a, 2], [$p2a, 2]])))
            ->and(rn02Instantanea())->toBe($antes);

        $r = rn02Post(RN02_URI, $cuerpo);
        $r->assertCreated();
        expect(rn02Asignacion($r))->toBe($asignacion);
    });
});

/*
|--------------------------------------------------------------------------
| Roles (tabla de la spec: dispensar = auxiliar y regente) y autenticación
|--------------------------------------------------------------------------
*/

describe('RN-02 roles y autenticación', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC1 roles: auxiliar y regente dispensan (201); médico, auditor y admin → 403 NO_AUTORIZADO sin cambios', function (string $rol, bool $permitido) {
        ['bodega' => $bodega, 'producto' => $producto, 'a' => $a, 'b' => $b] = rn02EscenarioSpec();
        $usuario = actuarComo($rol);
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, 2);

        if ($permitido) {
            rn02AfirmarDispensacion($r, $usuario, $bodega, [[$b, 2]]);
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
            expect(rn02Instantanea())->toBe($antes);
        }
    })->with([
        'auxiliar_farmacia' => ['auxiliar_farmacia', true],
        'regente_farmacia' => ['regente_farmacia', true],
        'medico' => ['medico', false],
        'auditor' => ['auditor', false],
        'admin' => ['admin', false],
    ]);

    it('RN-02-AC6 roles: auxiliar y regente previsualizan (200); médico, auditor y admin → 403 NO_AUTORIZADO sin cambios', function (string $rol, bool $permitido) {
        ['bodega' => $bodega, 'producto' => $producto, 'b' => $b] = rn02EscenarioSpec();
        actuarComo($rol);
        $antes = rn02Instantanea();

        $r = rn02Previsualizar($bodega, $producto, 2);

        if ($permitido) {
            $r->assertOk();
            expect(rn02Asignacion($r))->toBe(rn02Esperado([[$b, 2]]));
        } else {
            esperarError($r, 403, 'NO_AUTORIZADO');
        }
        expect(rn02Instantanea())->toBe($antes);
    })->with([
        'auxiliar_farmacia' => ['auxiliar_farmacia', true],
        'regente_farmacia' => ['regente_farmacia', true],
        'medico' => ['medico', false],
        'auditor' => ['auditor', false],
        'admin' => ['admin', false],
    ]);

    it('RN-02-AC1 RN-02-AC6 sin token o con token inválido → 401 NO_AUTENTICADO sin cambios', function (string $uri, bool $tokenInvalido) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        $antes = rn02Instantanea();
        $cabeceras = $tokenInvalido ? ['Authorization' => 'Bearer 999|token-que-no-existe'] : [];

        $r = test()->postJson($uri, rn02Cuerpo($bodega->id, [[$producto->id, 2]]), $cabeceras);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar sin token' => [RN02_URI, false],
        'dispensar con token inválido' => [RN02_URI, true],
        'previsualizar sin token' => [RN02_URI_PREVIA, false],
        'previsualizar con token inválido' => [RN02_URI_PREVIA, true],
    ]);
});

/*
|--------------------------------------------------------------------------
| Validación de entrada (S-07, S-27): 422 VALIDACION antes que las reglas de negocio
|--------------------------------------------------------------------------
*/

describe('RN-02 validación', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC1 RN-02-AC6 S-07: cantidad 0, negativa, decimal, booleana o no entera → 422 VALIDACION con detalles.campos["items.0.cantidad"] y sin cambios', function (string $uri, mixed $cantidad, bool $omitir) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $cuerpo = rn02Cuerpo($bodega->id, [[$producto->id, 1]]);
        if ($omitir) {
            unset($cuerpo['items'][0]['cantidad']);
        } else {
            $cuerpo['items'][0]['cantidad'] = $cantidad;
        }
        $r = rn02Post($uri, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('items.0.cantidad')
            ->and(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar' => [RN02_URI],
        'previsualizar' => [RN02_URI_PREVIA],
    ])->with([
        'cero' => [0, false],
        'menos uno' => [-1, false],
        'negativa grande' => [-100, false],
        'decimal 1.5' => [1.5, false],
        'entero escrito como decimal 2.0' => [2.0, false],
        'decimal 0.5' => [0.5, false],
        'decimal como texto "2.5"' => ['2.5', false],
        'texto no numérico' => ['dos', false],
        'cadena vacía' => ['', false],
        'null' => [null, false],
        'arreglo' => [[2], false],
        'booleano true' => [true, false],
        'booleano false' => [false, false],
        'ausente' => [null, true],
    ]);

    it('RN-02-AC1 RN-02-AC6: bodega, producto o items inexistentes o mal formados → 422 VALIDACION con el campo y sin cambios', function (string $uri, Closure $mutar, string $campo) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Post($uri, $mutar(rn02Cuerpo($bodega->id, [[$producto->id, 2]])));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar' => [RN02_URI],
        'previsualizar' => [RN02_URI_PREVIA],
    ])->with([
        'bodega inexistente' => [fn (array $c) => ['bodega_id' => 987654321] + $c, 'bodega_id'],
        'bodega ausente' => [fn (array $c) => array_diff_key($c, ['bodega_id' => 1]), 'bodega_id'],
        'bodega texto' => [fn (array $c) => ['bodega_id' => 'abc'] + $c, 'bodega_id'],
        'bodega decimal' => [fn (array $c) => ['bodega_id' => 1.5] + $c, 'bodega_id'],
        'bodega negativa' => [fn (array $c) => ['bodega_id' => -1] + $c, 'bodega_id'],
        'bodega booleana' => [fn (array $c) => ['bodega_id' => true] + $c, 'bodega_id'],
        'bodega fuera de rango bigint' => [fn (array $c) => ['bodega_id' => '99999999999999999999'] + $c, 'bodega_id'],
        'ítem prescrito inexistente' => [fn (array $c) => ['items' => [['prescripcion_item_id' => 987654321, 'cantidad' => 2]]] + $c, 'items.0.prescripcion_item_id'],
        'ítem prescrito ausente' => [fn (array $c) => ['items' => [['cantidad' => 2]]] + $c, 'items.0.prescripcion_item_id'],
        'ítem prescrito texto' => [fn (array $c) => ['items' => [['prescripcion_item_id' => 'abc', 'cantidad' => 2]]] + $c, 'items.0.prescripcion_item_id'],
        'ítem prescrito decimal' => [fn (array $c) => ['items' => [['prescripcion_item_id' => 1.5, 'cantidad' => 2]]] + $c, 'items.0.prescripcion_item_id'],
        'ítem prescrito booleano' => [fn (array $c) => ['items' => [['prescripcion_item_id' => true, 'cantidad' => 2]]] + $c, 'items.0.prescripcion_item_id'],
        'items ausente' => [fn (array $c) => array_diff_key($c, ['items' => 1]), 'items'],
        'items vacío' => [fn (array $c) => ['items' => []] + $c, 'items'],
        'items no es lista' => [fn (array $c) => ['items' => 'todo'] + $c, 'items'],
        'ítem no es objeto' => [fn (array $c) => ['items' => [5]] + $c, 'items.0'],
    ]);

    it('RN-02-AC1 S-04: si un ítem válido va junto a uno inválido, 422 VALIDACION y no se descuenta el válido', function () {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        $otro = Producto::factory()->create();
        rn02LoteConStock($bodega, $otro, '2027-01-01', 10);
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Post(RN02_URI, rn02Cuerpo($bodega->id, [[$producto->id, 2], [$otro->id, 0]]));

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('items.1.cantidad')
            ->and(rn02Instantanea())->toBe($antes);
    });

    it('RN-02-AC1 robustez: una cantidad entera enorme nunca da 500 ni descuenta nada (409 STOCK_INSUFICIENTE o 422 VALIDACION)', function (int $cantidad) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        actuarComo('auxiliar_farmacia');
        $antes = rn02Instantanea();

        $r = rn02Dispensar($bodega, $producto, $cantidad);

        expect($r->status())->toBeIn([409, 422]);
        esperarError($r, $r->status(), $r->status() === 409 ? 'STOCK_INSUFICIENTE' : 'VALIDACION');
        expect(rn02Instantanea())->toBe($antes);
    })->with([
        '2^31 (fuera de integer de PostgreSQL)' => [2_147_483_648],
        'PHP_INT_MAX' => [PHP_INT_MAX],
    ]);
});

/*
|--------------------------------------------------------------------------
| Supuestos S-41, S-45, S-46, S-47 y rendimiento de la previsualización
|--------------------------------------------------------------------------
*/

describe('RN-02 supuestos de la dispensación', function () {
    beforeEach(fn () => rn02Congelar());

    it('RN-02-AC1 RN-02-AC6 S-45: prescripción de otro paciente o ítem de otra prescripción → 422 PRESCRIPCION_NO_CORRESPONDE y sin cambios', function (string $uri, string $caso) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn02Cuerpo($bodega->id, [[$producto->id, 2]]);

        if ($caso === 'paciente') {
            $otroPaciente = Paciente::factory()->create();
            $cuerpo['paciente_id'] = $otroPaciente->id;
        } else {
            // Otra prescripción del MISMO paciente y del mismo producto: solo falla la pertenencia del ítem.
            $otra = Prescripcion::factory()->create(['paciente_id' => $cuerpo['paciente_id']]);
            $ajeno = PrescripcionItem::factory()->create([
                'prescripcion_id' => $otra->id,
                'producto_id' => $producto->id,
                'cantidad' => 1000,
            ]);
            $cuerpo['items'][0]['prescripcion_item_id'] = $ajeno->id;
        }
        $antes = rn02Instantanea();

        $r = rn02Post($uri, $cuerpo);

        esperarError($r, 422, 'PRESCRIPCION_NO_CORRESPONDE');
        expect($r->json('error.detalles.prescripcion_id'))->toBe($cuerpo['prescripcion_id']);
        if ($caso === 'paciente') {
            expect($r->json('error.detalles.paciente_id'))->toBe($cuerpo['paciente_id']);
        } else {
            expect($r->json('error.detalles.prescripcion_item_ids'))->toBe([$cuerpo['items'][0]['prescripcion_item_id']]);
        }
        expect(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar' => [RN02_URI],
        'previsualizar' => [RN02_URI_PREVIA],
    ])->with([
        'prescripción de otro paciente' => ['paciente'],
        'ítem de otra prescripción del paciente' => ['item'],
    ]);

    it('RN-02-AC1 RN-02-AC6 S-41: más de 50 ítems, ítem prescrito repetido o claves extra en un ítem → 422 VALIDACION con el campo y sin cambios', function (string $uri, Closure $mutar, string $campo) {
        $bodega = Bodega::factory()->create();
        $productos = Producto::factory()->count(2)->create();
        foreach ($productos as $p) {
            rn02LoteConStock($bodega, $p, '2027-06-01', 10);
        }
        actuarComo('auxiliar_farmacia');
        $cuerpo = $mutar(rn02Cuerpo($bodega->id, [[$productos[0]->id, 1], [$productos[1]->id, 1]]), $bodega);
        $antes = rn02Instantanea();

        $r = rn02Post($uri, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar' => [RN02_URI],
        'previsualizar' => [RN02_URI_PREVIA],
    ])->with([
        '51 ítems' => [function (array $c, Bodega $bodega): array {
            $productos = Producto::factory()->count(51)->create();

            return rn02Cuerpo($bodega->id, $productos->map(fn (Producto $p): array => [$p->id, 1])->all());
        }, 'items'],
        'prescripcion_item_id repetido' => [function (array $c): array {
            $c['items'][1]['prescripcion_item_id'] = $c['items'][0]['prescripcion_item_id'];

            return $c;
        }, 'items.1.prescripcion_item_id'],
        'prescripcion_item_id repetido con otro tipo JSON (cadena)' => [function (array $c): array {
            $c['items'][1]['prescripcion_item_id'] = (string) $c['items'][0]['prescripcion_item_id'];

            return $c;
        }, 'items.1.prescripcion_item_id'],
        'prescripcion_item_id repetido con otro tipo JSON (decimal)' => [function (array $c): array {
            $c['items'][1]['prescripcion_item_id'] = (float) $c['items'][0]['prescripcion_item_id'];

            return $c;
        }, 'items.1.prescripcion_item_id'],
        'clave extra producto_id' => [function (array $c): array {
            $c['items'][0]['producto_id'] = Producto::query()->value('id');

            return $c;
        }, 'items.0'],
        'clave extra lote_id' => [function (array $c): array {
            $c['items'][0]['lote_id'] = Lote::query()->value('id');

            return $c;
        }, 'items.0'],
    ]);

    it('RN-02-AC1 S-41 S-47: 50 ítems (el máximo) se aceptan y cada ítem sale por su propio FEFO en el orden de la petición', function () {
        $bodega = Bodega::factory()->create();
        $productos = Producto::factory()->count(50)->create();
        $esperado = [];
        foreach ($productos as $p) {
            $tarde = rn02LoteConStock($bodega, $p, '2027-06-01', 5);
            $pronto = rn02LoteConStock($bodega, $p, '2026-12-01', 1);
            $esperado[] = [$pronto, 1];
            $esperado[] = [$tarde, 1];
        }
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = rn02Cuerpo($bodega->id, $productos->map(fn (Producto $p): array => [$p->id, 2])->all());
        expect($cuerpo['items'])->toHaveCount(StoreDispensacionRequest::ITEMS_MAXIMO);

        $previa = rn02Post(RN02_URI_PREVIA, $cuerpo);
        $previa->assertOk();
        expect(rn02Asignacion($previa))->toBe(rn02Esperado($esperado));

        $r = rn02Post(RN02_URI, $cuerpo);

        rn02AfirmarDispensacion($r, $usuario, $bodega, $esperado);
        expect(DB::table('dispensacion_items')->count())->toBe(50);
    });

    it('RN-02-AC1 RN-02-AC6 S-46: una bodega inactiva → 422 VALIDACION con bodega_id en detalles.campos y sin cambios', function (string $uri) {
        ['bodega' => $bodega, 'producto' => $producto] = rn02EscenarioSpec();
        $bodega->forceFill(['activa' => false])->save();
        actuarComo('auxiliar_farmacia');
        $cuerpo = rn02Cuerpo($bodega->id, [[$producto->id, 2]]);
        $antes = rn02Instantanea();

        $r = rn02Post($uri, $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('bodega_id')
            ->and(rn02Instantanea())->toBe($antes);
    })->with([
        'dispensar' => [RN02_URI],
        'previsualizar' => [RN02_URI_PREVIA],
    ]);

    it('RN-02-AC2 RN-02-AC6 S-47: con dos ítems de productos distintos, los detalles van por ítem en el orden de la petición y por FEFO dentro de cada uno', function () {
        $bodega = Bodega::factory()->create();
        // p1 se crea antes (ids menores en producto, lotes y existencias) pero se pide en segundo lugar.
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $p1Tarde = rn02LoteConStock($bodega, $p1, '2027-05-01', 10);
        $p1Pronto = rn02LoteConStock($bodega, $p1, '2026-12-01', 2);
        $p2Tarde = rn02LoteConStock($bodega, $p2, '2027-09-01', 10);
        $p2Pronto = rn02LoteConStock($bodega, $p2, '2026-11-20', 1);
        $usuario = actuarComo('regente_farmacia');
        $cuerpo = rn02Cuerpo($bodega->id, [[$p2->id, 3], [$p1->id, 4]]);
        $esperado = [[$p2Pronto, 1], [$p2Tarde, 2], [$p1Pronto, 2], [$p1Tarde, 2]];

        $previa = rn02Post(RN02_URI_PREVIA, $cuerpo);
        $previa->assertOk();
        expect(rn02Asignacion($previa))->toBe(rn02Esperado($esperado))
            ->and(array_column(cuerpoDatos($previa)['detalles'], 'prescripcion_item_id'))->toBe([
                $cuerpo['items'][0]['prescripcion_item_id'], $cuerpo['items'][0]['prescripcion_item_id'],
                $cuerpo['items'][1]['prescripcion_item_id'], $cuerpo['items'][1]['prescripcion_item_id'],
            ]);

        $r = rn02Post(RN02_URI, $cuerpo);

        rn02AfirmarDispensacion($r, $usuario, $bodega, $esperado);
    });

    it('RN-02-AC6 rendimiento: la previsualización consulta productos un número fijo de veces, sin importar cuántos ítems lleve (sin N+1)', function () {
        $bodega = Bodega::factory()->create();
        $productos = Producto::factory()->count(6)->create();
        foreach ($productos as $p) {
            rn02LoteConStock($bodega, $p, '2027-06-01', 10);
        }
        actuarComo('auxiliar_farmacia');

        $consultas = 0;
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            if (preg_match('/\bfrom\s+"?productos"?/i', $q->sql) === 1) {
                $consultas++;
            }
        });

        $consultasAProductos = function (int $items) use ($bodega, $productos, &$consultas): int {
            $cuerpo = rn02Cuerpo($bodega->id, $productos->take($items)->map(fn (Producto $p): array => [$p->id, 1])->values()->all());
            $consultas = 0;

            $r = rn02Post(RN02_URI_PREVIA, $cuerpo);

            $r->assertOk();
            expect(cuerpoDatos($r)['items'])->toHaveCount($items)
                ->and(array_column(cuerpoDatos($r)['items'], 'producto'))->each->toHaveKeys(['id', 'codigo', 'nombre', 'control_especial']);

            return $consultas;
        };

        $conUno = $consultasAProductos(1);
        $conSeis = $consultasAProductos(6);

        expect($conSeis)->toBe($conUno)
            ->and($conSeis)->toBeLessThanOrEqual(1);
    });
});
