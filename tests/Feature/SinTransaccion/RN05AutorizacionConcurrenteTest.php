<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Support\Negocio;
use Database\Seeders\UsuariosSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Concurrencia;

/*
 * RN-05-AC10 — autorizaciones (y rechazos) simultáneos de la misma dispensación pendiente.
 *
 * Procesos separados (pcntl_fork) con su propia conexión a PostgreSQL y datos con commit real (sin
 * RefreshDatabase). Todos arrancan a la vez tras la barrera de Tests\Concerns\Concurrencia y llaman
 * por el kernel HTTP real a POST /api/v1/dispensaciones/{id}/autorizar|rechazar.
 *
 * Lo que debe resolver la carrera es el lockForUpdate de la dispensación y la verificación del
 * estado dentro de la transacción: exactamente una 200 y el resto 409 TRANSICION_INVALIDA; una
 * sola salida de stock. Un 409 CONFLICTO_CONCURRENCIA (S-48) o un 500 incumplen AC10.
 * Variantes: autorizar y rechazar a la vez; dos pendientes distintas por la última unidad
 * (RN-05-AC6 bajo carrera); y pendientes con los ítems en orden inverso (S-56, sin interbloqueo).
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-05 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Un hijo por petición; todos arrancan a la vez. Devuelve por hijo: status, código de error y estado.
 *
 * @param  list<array{usuario: User, uri: string, cuerpo: array<string, mixed>}>  $peticiones
 * @return list<array{status: int|string, codigo: ?string, estado: ?string}>
 */
function rn05cEnParalelo(array $peticiones): array
{
    $peticiones = array_values($peticiones);

    $crudos = Concurrencia::enParalelo(
        count($peticiones),
        function (int $i) use ($peticiones): string {
            $resultado = ['status' => 'sin_resultado', 'codigo' => null, 'estado' => null];
            try {
                $r = test()->postJson($peticiones[$i]['uri'], $peticiones[$i]['cuerpo']);
                $resultado = [
                    'status' => $r->getStatusCode(),
                    'codigo' => $r->json('error.codigo'),
                    'estado' => $r->json('data.estado') ?? $r->json('estado'),
                ];
            } catch (Throwable $e) {
                $resultado['status'] = 'excepcion: '.$e::class.': '.$e->getMessage();
            }

            return (string) json_encode($resultado);
        },
        function (int $i) use ($peticiones): void {
            Sanctum::actingAs($peticiones[$i]['usuario']);
        },
    );

    return array_map(fn (string $crudo): array => json_decode($crudo, true), $crudos);
}

/** @param list<array<string, mixed>> $resultados */
function rn05cResumen(array $resultados): string
{
    return implode(', ', array_map(
        fn (array $r): string => $r['status'].'|'.($r['codigo'] ?? '').'|'.($r['estado'] ?? ''),
        $resultados,
    ));
}

function rn05cUri(int $id, string $accion): string
{
    return "/api/v1/dispensaciones/{$id}/{$accion}";
}

/** Regentes: los dos de la semilla (S-19) y, si hacen falta más, regentes adicionales. @return list<User> */
function rn05cRegentes(int $n): array
{
    (new UsuariosSeeder)->run();
    $regentes = [
        User::query()->where('email', 'regente@farma.test')->firstOrFail(),
        User::query()->where('email', 'regente2@farma.test')->firstOrFail(),
    ];
    for ($i = count($regentes); $i < $n; $i++) {
        $regentes[] = User::factory()->create(['rol' => Rol::RegenteFarmacia, 'email' => "rn05c-regente-{$i}@farma.test"]);
    }

    return array_slice($regentes, 0, $n);
}

/** Lote nuevo con `cantidad` en la bodega, por una ENTRADA real con commit. */
function rn05cStock(Bodega $bodega, Producto $producto, int $cantidad, int $venceEnDias = 200): Existencia
{
    $lote = Lote::factory()->create([
        'producto_id' => $producto->id,
        'fecha_vencimiento' => Negocio::hoy()->addDays($venceEnDias)->toDateString(),
    ]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);
    $carga = User::query()->where('email', 'rn05c-carga@farma.test')->first()
        ?? User::factory()->create(['rol' => Rol::RegenteFarmacia, 'email' => 'rn05c-carga@farma.test']);

    DB::transaction(function () use ($existencia, $cantidad, $carga): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $carga->id);
    });

    return $existencia->refresh();
}

function rn05cStockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Crea por HTTP (con commit) una dispensación pendiente como `$creador` y devuelve su id.
 *
 * @param  list<array{0: PrescripcionItem, 1: int}>  $items
 */
function rn05cCrearPendiente(User $creador, Prescripcion $prescripcion, Bodega $bodega, array $items): int
{
    Sanctum::actingAs($creador);
    $r = test()->postJson('/api/v1/dispensaciones', [
        'paciente_id' => $prescripcion->paciente_id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => $bodega->id,
        'items' => array_map(fn (array $it): array => ['prescripcion_item_id' => $it[0]->id, 'cantidad' => $it[1]], $items),
    ], cabecerasDispensacion());

    $r->assertCreated();
    expect($r->json('data.estado'))->toBe('PENDIENTE_AUTORIZACION');
    $id = $r->json('data.id');
    expect($id)->toBeInt();

    return $id;
}

/**
 * Escenario con commit: producto de control con 2 lotes (FEFO obliga a bloquear ambos), una
 * prescripción holgada y una pendiente creada por un auxiliar.
 *
 * @return array{id: int, creador: User, existencias: list<Existencia>, cantidad: int}
 */
function rn05cPendienteDosLotes(): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->controlEspecial()->create();
    $primero = rn05cStock($bodega, $producto, 2, 30);
    $segundo = rn05cStock($bodega, $producto, 10, 90);
    $prescripcion = Prescripcion::factory()->create();
    $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 100]);
    $creador = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);

    $id = rn05cCrearPendiente($creador, $prescripcion, $bodega, [[$item, 5]]);

    return ['id' => $id, 'creador' => $creador, 'existencias' => [$primero, $segundo], 'cantidad' => 5];
}

/** Cadena de saldos de la existencia sin huecos (RN-06-AC2). */
function rn05cAfirmarCadena(Existencia $existencia, string $contexto): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} movimiento #{$i}: hueco o saldo duplicado")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn05cStockDe($existencia), "{$contexto}: el kardex no cuadra con la existencia");
}

/** Movimientos de kardex de la dispensación. */
function rn05cSalidas(int $id): Collection
{
    return DB::table('movimientos_kardex')->where('documento_tipo', 'dispensacion')->where('documento_id', $id)->get();
}

it('RN-05-AC10: N regentes distintos del creador autorizan a la vez la misma pendiente → exactamente una 200 CONFIRMADA y N−1 409 TRANSICION_INVALIDA; una sola salida FEFO (2 lotes)', function (int $procesos, int $rondas) {
    $regentes = rn05cRegentes($procesos);

    foreach (range(1, $rondas) as $ronda) {
        $p = rn05cPendienteDosLotes();
        [$primero, $segundo] = $p['existencias'];

        $resultados = rn05cEnParalelo(array_map(
            fn (User $regente): array => ['usuario' => $regente, 'uri' => rn05cUri($p['id'], 'autorizar'), 'cuerpo' => []],
            $regentes,
        ));
        $detalle = "ronda {$ronda}: ".rn05cResumen($resultados);

        $ganadores = array_keys(array_filter($resultados, fn (array $r): bool => $r['status'] === 200));
        $perdedores = array_filter($resultados, fn (array $r): bool => $r['status'] === 409 && $r['codigo'] === 'TRANSICION_INVALIDA');
        expect($ganadores)->toHaveCount(1, $detalle)
            ->and($perdedores)->toHaveCount($procesos - 1, $detalle)
            ->and($resultados[$ganadores[0]]['estado'])->toBe('CONFIRMADA', $detalle);

        $fila = DB::table('dispensaciones')->where('id', $p['id'])->first();
        expect($fila->estado)->toBe('CONFIRMADA', $detalle)
            ->and((int) $fila->autorizado_por_id)->toBe($regentes[$ganadores[0]]->id, $detalle)
            ->and($fila->autorizado_en)->not->toBeNull()
            ->and($fila->rechazado_por_id)->toBeNull();

        $salidas = rn05cSalidas($p['id']);
        expect($salidas->pluck('tipo')->unique()->values()->all())->toBe(['SALIDA_DISPENSACION'], $detalle)
            // S-58: las salidas van a nombre del regente que ganó la autorización, no del creador.
            ->and($salidas->pluck('usuario_id')->map(fn ($u): int => (int) $u)->unique()->values()->all())->toBe([$regentes[$ganadores[0]]->id], $detalle)
            ->and((int) $fila->dispensado_por_id)->toBe($p['creador']->id, $detalle)
            ->and((int) $salidas->sum('cantidad'))->toBe(-$p['cantidad'], $detalle)
            ->and($salidas)->toHaveCount(2, $detalle)
            ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $p['id'])->sum('cantidad'))->toEqual($p['cantidad'])
            ->and(rn05cStockDe($primero))->toBe(0, $detalle)
            ->and(rn05cStockDe($segundo))->toBe(7, $detalle);

        rn05cAfirmarCadena($primero, $detalle);
        rn05cAfirmarCadena($segundo, $detalle);
    }
})->with([
    'dos regentes (semilla)' => [2, 5],
    'cuatro regentes' => [4, 3],
    'ocho regentes' => [8, 2],
])->group('concurrencia');

it('RN-05-AC10 RN-05-AC8: autorizar y rechazar a la vez la misma pendiente → una gana (200) y la otra 409 TRANSICION_INVALIDA; el estado final es coherente con la ganadora', function (int $autorizan, int $rechazan, int $rondas) {
    $regentes = rn05cRegentes($autorizan + $rechazan);

    foreach (range(1, $rondas) as $ronda) {
        $p = rn05cPendienteDosLotes();
        [$primero, $segundo] = $p['existencias'];

        $peticiones = [];
        foreach ($regentes as $i => $regente) {
            $peticiones[] = $i < $autorizan
                ? ['usuario' => $regente, 'uri' => rn05cUri($p['id'], 'autorizar'), 'cuerpo' => []]
                : ['usuario' => $regente, 'uri' => rn05cUri($p['id'], 'rechazar'), 'cuerpo' => ['motivo' => "Rechazo concurrente {$ronda}-{$i} sin soporte"]];
        }
        $resultados = rn05cEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn05cResumen($resultados);

        $ganadores = array_keys(array_filter($resultados, fn (array $r): bool => $r['status'] === 200));
        $perdedores = array_filter($resultados, fn (array $r): bool => $r['status'] === 409 && $r['codigo'] === 'TRANSICION_INVALIDA');
        expect($ganadores)->toHaveCount(1, $detalle)
            ->and($perdedores)->toHaveCount($autorizan + $rechazan - 1, $detalle);

        $ganador = $ganadores[0];
        $fila = DB::table('dispensaciones')->where('id', $p['id'])->first();
        $salidas = rn05cSalidas($p['id']);

        if ($ganador < $autorizan) {
            expect($resultados[$ganador]['estado'])->toBe('CONFIRMADA', $detalle)
                ->and($fila->estado)->toBe('CONFIRMADA', $detalle)
                ->and((int) $fila->autorizado_por_id)->toBe($regentes[$ganador]->id)
                ->and($fila->rechazado_por_id)->toBeNull()
                ->and($fila->motivo_rechazo)->toBeNull()
                ->and($salidas->pluck('usuario_id')->map(fn ($u): int => (int) $u)->unique()->values()->all())->toBe([$regentes[$ganador]->id], $detalle)
                ->and((int) $salidas->sum('cantidad'))->toBe(-$p['cantidad'], $detalle)
                ->and(rn05cStockDe($primero) + rn05cStockDe($segundo))->toBe(12 - $p['cantidad'], $detalle);
        } else {
            expect($resultados[$ganador]['estado'])->toBe('RECHAZADA', $detalle)
                ->and($fila->estado)->toBe('RECHAZADA', $detalle)
                ->and((int) $fila->rechazado_por_id)->toBe($regentes[$ganador]->id)
                ->and($fila->rechazado_en)->not->toBeNull()
                ->and($fila->motivo_rechazo)->toBe("Rechazo concurrente {$ronda}-{$ganador} sin soporte")
                ->and($fila->autorizado_por_id)->toBeNull()
                ->and($salidas)->toHaveCount(0, $detalle)
                ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $p['id'])->count())->toBe(0)
                ->and(rn05cStockDe($primero))->toBe(2, $detalle)
                ->and(rn05cStockDe($segundo))->toBe(10, $detalle);
        }

        rn05cAfirmarCadena($primero, $detalle);
        rn05cAfirmarCadena($segundo, $detalle);
    }
})->with([
    'uno autoriza y uno rechaza (semilla)' => [1, 1, 6],
    'tres autorizan y tres rechazan' => [3, 3, 3],
])->group('concurrencia');

it('RN-05-AC10 RN-05-AC6: N pendientes distintas por la última unidad, autorizadas a la vez por regentes distintos → una 200 y N−1 409 STOCK_INSUFICIENTE que siguen PENDIENTE; stock final 0 y una sola salida', function (int $procesos) {
    $regentes = rn05cRegentes($procesos);
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->controlEspecial()->create();
    $existencia = rn05cStock($bodega, $producto, 1);
    $prescripcion = Prescripcion::factory()->create();
    $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 100]);
    $creador = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);

    $ids = [];
    for ($i = 0; $i < $procesos; $i++) {
        $ids[] = rn05cCrearPendiente($creador, $prescripcion, $bodega, [[$item, 1]]);
    }

    $peticiones = [];
    foreach ($regentes as $i => $regente) {
        $peticiones[] = ['usuario' => $regente, 'uri' => rn05cUri($ids[$i], 'autorizar'), 'cuerpo' => []];
    }
    $resultados = rn05cEnParalelo($peticiones);
    $detalle = rn05cResumen($resultados);

    $ganadores = array_keys(array_filter($resultados, fn (array $r): bool => $r['status'] === 200));
    $sinStock = array_keys(array_filter($resultados, fn (array $r): bool => $r['status'] === 409 && $r['codigo'] === 'STOCK_INSUFICIENTE'));
    expect($ganadores)->toHaveCount(1, $detalle)
        ->and($sinStock)->toHaveCount($procesos - 1, $detalle)
        ->and(rn05cStockDe($existencia))->toBe(0)
        ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(1);

    expect(DB::table('dispensaciones')->where('id', $ids[$ganadores[0]])->value('estado'))->toBe('CONFIRMADA');
    foreach ($sinStock as $i) {
        expect(DB::table('dispensaciones')->where('id', $ids[$i])->value('estado'))->toBe('PENDIENTE_AUTORIZACION', $detalle)
            ->and(rn05cSalidas($ids[$i]))->toHaveCount(0);
    }
    rn05cAfirmarCadena($existencia, $detalle);
})->with([
    'dos pendientes' => 2,
    'seis pendientes' => 6,
])->group('concurrencia');

it('RN-05-AC10 S-56: dos pendientes mixtas con los ítems en orden inverso, autorizadas a la vez → ambas 200 sin interbloqueo (ni CONFLICTO_CONCURRENCIA) y stock descontado una vez por cada una', function (int $rondas) {
    $regentes = rn05cRegentes(2);
    $creador = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);

    foreach (range(1, $rondas) as $ronda) {
        $bodega = Bodega::factory()->create();
        $control = Producto::factory()->controlEspecial()->create();
        $comun = Producto::factory()->create();
        $exControl = rn05cStock($bodega, $control, 20);
        $exComun = rn05cStock($bodega, $comun, 20);
        $prescripcion = Prescripcion::factory()->create();
        $itControl = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $control->id, 'cantidad' => 100]);
        $itComun = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $comun->id, 'cantidad' => 100]);

        $a = rn05cCrearPendiente($creador, $prescripcion, $bodega, [[$itControl, 3], [$itComun, 4]]);
        $b = rn05cCrearPendiente($creador, $prescripcion, $bodega, [[$itComun, 5], [$itControl, 6]]);

        $resultados = rn05cEnParalelo([
            ['usuario' => $regentes[0], 'uri' => rn05cUri($a, 'autorizar'), 'cuerpo' => []],
            ['usuario' => $regentes[1], 'uri' => rn05cUri($b, 'autorizar'), 'cuerpo' => []],
        ]);
        $detalle = "ronda {$ronda}: ".rn05cResumen($resultados);

        foreach ($resultados as $r) {
            expect($r['status'])->toBe(200, $detalle)
                ->and($r['estado'])->toBe('CONFIRMADA', $detalle);
        }
        expect(rn05cStockDe($exControl))->toBe(20 - 3 - 6, $detalle)
            ->and(rn05cStockDe($exComun))->toBe(20 - 4 - 5, $detalle)
            ->and((int) rn05cSalidas($a)->sum('cantidad'))->toBe(-7)
            ->and((int) rn05cSalidas($b)->sum('cantidad'))->toBe(-11);

        rn05cAfirmarCadena($exControl, $detalle);
        rn05cAfirmarCadena($exComun, $detalle);
    }
})->with([
    'cinco rondas' => 5,
])->group('concurrencia');
