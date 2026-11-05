<?php

declare(strict_types=1);

use App\Enums\AccionTraslado;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\BloqueoTraslado;
use App\Support\Negocio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Concurrencia;

/*
 * RN-07-AC11 — despachos simultáneos del mismo traslado (y variantes).
 *
 * Procesos separados (pcntl_fork) con su propia conexión a PostgreSQL y datos con commit real (sin
 * RefreshDatabase). Todos arrancan a la vez tras la barrera de Tests\Concerns\Concurrencia y llaman
 * por el kernel HTTP real a POST /api/v1/traslados/{id}/{acción}.
 *
 * Lo que resuelve la carrera es el lockForUpdate del traslado y la revalidación del estado dentro
 * de la transacción: exactamente una 200 y el resto 409 TRANSICION_INVALIDA; una sola
 * SALIDA_TRASLADO por ítem. Un 409 CONFLICTO_CONCURRENCIA (S-48) o un 500 incumplen AC11.
 * Variantes: despachar y anular a la vez; recibir dos veces a la vez; dos traslados distintos por
 * el mismo stock (RN-07-AC10 bajo carrera); dos traslados con los lotes en orden inverso (sin
 * interbloqueo).
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-07 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

const RN07C_URI = '/api/v1/traslados';
const RN07C_MOTIVO = 'Anulación concurrente de prueba RN-07';

/**
 * Un hijo por petición; todos arrancan a la vez. Devuelve por hijo: status, código de error y estado.
 *
 * @param  list<array{usuario: User, uri: string, cuerpo: array<string, mixed>}>  $peticiones
 * @return list<array{status: int|string, codigo: ?string, estado: ?string}>
 */
function rn07cEnParalelo(array $peticiones): array
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
function rn07cResumen(array $resultados): string
{
    return implode(', ', array_map(
        fn (array $r): string => $r['status'].'|'.($r['codigo'] ?? '').'|'.($r['estado'] ?? ''),
        $resultados,
    ));
}

function rn07cUsuario(Rol $rol): User
{
    return User::factory()->create(['rol' => $rol]);
}

/** Existencia con `cantidad` cargada por una ENTRADA real con commit. */
function rn07cStock(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);
    $carga = User::query()->where('email', 'rn07c-carga@farma.test')->first()
        ?? User::factory()->create(['rol' => Rol::RegenteFarmacia, 'email' => 'rn07c-carga@farma.test']);

    DB::transaction(function () use ($existencia, $cantidad, $carga): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $carga->id);
    });

    return $existencia->refresh();
}

function rn07cLote(Producto $producto): Lote
{
    return Lote::factory()->create([
        'producto_id' => $producto->id,
        'fecha_vencimiento' => Negocio::hoy()->addDays(200)->toDateString(),
    ]);
}

function rn07cStockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Crea por HTTP (con commit) un traslado y lo lleva hasta `estado` por el camino real.
 *
 * @param  list<array{0: Lote, 1: int}>  $items  [[lote, cantidad], …]
 */
function rn07cTraslado(Bodega $origen, Bodega $destino, array $items, User $creador, User $aprobador, string $estado = 'APROBADO', ?User $despachador = null): int
{
    Sanctum::actingAs($creador);
    $r = test()->postJson(RN07C_URI, [
        'bodega_origen_id' => $origen->id,
        'bodega_destino_id' => $destino->id,
        'items' => array_map(fn (array $it): array => ['producto_id' => $it[0]->producto_id, 'lote_id' => $it[0]->id, 'cantidad' => $it[1]], $items),
    ]);
    expect($r->getStatusCode())->toBe(201, 'crear: '.$r->getContent());
    $id = $r->json('data.id');
    expect($id)->toBeInt();

    $pasos = [['solicitar', $creador], ['aprobar', $aprobador]];
    if ($estado === 'EN_TRANSITO') {
        $pasos[] = ['despachar', $despachador ?? $creador];
    }
    foreach ($pasos as [$accion, $usuario]) {
        Sanctum::actingAs($usuario);
        $p = test()->postJson(RN07C_URI."/{$id}/{$accion}", []);
        expect($p->getStatusCode())->toBe(200, "{$accion}: ".$p->getContent());
    }
    expect(DB::table('traslados')->where('id', $id)->value('estado'))->toBe($estado);

    return $id;
}

/**
 * Escenario con commit: 2 ítems de 2 lotes (obliga a bloquear 2 existencias), traslado APROBADO.
 *
 * @return array{id: int, origen: Bodega, destino: Bodega, existencias: list<Existencia>, lotes: list<Lote>, cantidades: list<int>, stocks: list<int>, creador: User}
 */
function rn07cEscenario(string $estado = 'APROBADO'): array
{
    $origen = Bodega::factory()->create();
    $destino = Bodega::factory()->create();
    $lotes = [rn07cLote(Producto::factory()->create()), rn07cLote(Producto::factory()->create())];
    $stocks = [12, 9];
    $cantidades = [10, 4];
    $existencias = [rn07cStock($origen, $lotes[0], $stocks[0]), rn07cStock($origen, $lotes[1], $stocks[1])];
    $creador = rn07cUsuario(Rol::AuxiliarFarmacia);

    $id = rn07cTraslado($origen, $destino, [[$lotes[0], $cantidades[0]], [$lotes[1], $cantidades[1]]], $creador, rn07cUsuario(Rol::RegenteFarmacia), $estado);

    return compact('id', 'origen', 'destino', 'existencias', 'lotes', 'cantidades', 'stocks', 'creador');
}

/** Movimientos del traslado (documento `traslado` + id, S-35). */
function rn07cMovs(int $id): Collection
{
    return DB::table('movimientos_kardex')->where('documento_tipo', 'traslado')->where('documento_id', $id)->orderBy('id')->get();
}

/** Cadena de saldos sin huecos (RN-06-AC2) para la existencia. */
function rn07cAfirmarCadena(int $existenciaId, string $contexto): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existenciaId)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} movimiento #{$i}: hueco o saldo duplicado")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe((int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad'), "{$contexto}: el kardex no cuadra con la existencia");
}

/** @param list<array<string, mixed>> $resultados @return list<int> */
function rn07cIndices(array $resultados, int $status, ?string $codigo = null): array
{
    return array_keys(array_filter(
        $resultados,
        fn (array $r): bool => $r['status'] === $status && ($codigo === null || $r['codigo'] === $codigo),
    ));
}

it('RN-07-AC11: N despachos simultáneos del mismo traslado APROBADO → exactamente una 200 EN_TRANSITO y N−1 409 TRANSICION_INVALIDA; una sola SALIDA_TRASLADO por ítem', function (int $procesos, int $rondas) {
    $despachadores = [];
    for ($i = 0; $i < $procesos; $i++) {
        $despachadores[] = rn07cUsuario($i % 2 === 0 ? Rol::AuxiliarFarmacia : Rol::RegenteFarmacia);
    }

    foreach (range(1, $rondas) as $ronda) {
        $s = rn07cEscenario();

        $resultados = rn07cEnParalelo(array_map(
            fn (User $u): array => ['usuario' => $u, 'uri' => RN07C_URI."/{$s['id']}/despachar", 'cuerpo' => []],
            $despachadores,
        ));
        $detalle = "ronda {$ronda}: ".rn07cResumen($resultados);

        $ganadores = rn07cIndices($resultados, 200);
        expect($ganadores)->toHaveCount(1, $detalle)
            ->and(rn07cIndices($resultados, 409, 'TRANSICION_INVALIDA'))->toHaveCount($procesos - 1, $detalle)
            ->and($resultados[$ganadores[0]]['estado'])->toBe('EN_TRANSITO', $detalle);

        $fila = DB::table('traslados')->where('id', $s['id'])->first();
        expect($fila->estado)->toBe('EN_TRANSITO', $detalle)
            ->and((int) $fila->despachado_por_id)->toBe($despachadores[$ganadores[0]]->id, $detalle)
            ->and($fila->despachado_en)->not->toBeNull();

        $movs = rn07cMovs($s['id']);
        expect($movs)->toHaveCount(2, $detalle)
            ->and($movs->pluck('tipo')->unique()->values()->all())->toBe(['SALIDA_TRASLADO'], $detalle)
            ->and($movs->pluck('usuario_id')->map(fn ($u): int => (int) $u)->unique()->values()->all())->toBe([$despachadores[$ganadores[0]]->id], $detalle);

        foreach ($s['existencias'] as $k => $existencia) {
            $salidas = $movs->where('existencia_id', $existencia->id);
            expect($salidas)->toHaveCount(1, "{$detalle} (ítem {$k})")
                ->and((int) $salidas->first()->cantidad)->toBe(-$s['cantidades'][$k])
                ->and(rn07cStockDe($existencia))->toBe($s['stocks'][$k] - $s['cantidades'][$k], $detalle);
            rn07cAfirmarCadena($existencia->id, $detalle);
        }
        expect(DB::table('existencias')->where('bodega_id', $s['destino']->id)->sum('cantidad'))->toEqual(0);
    }
})->with([
    'dos despachos' => [2, 5],
    'cuatro despachos' => [4, 3],
    'ocho despachos' => [8, 2],
])->group('concurrencia');

it('RN-07-AC11 RN-07-AC8: despachar y anular a la vez el mismo APROBADO → una gana (200) y la otra 409 TRANSICION_INVALIDA; el estado final es coherente con la ganadora', function (int $despachan, int $anulan, int $rondas) {
    foreach (range(1, $rondas) as $ronda) {
        $s = rn07cEscenario();

        $peticiones = [];
        for ($i = 0; $i < $despachan; $i++) {
            $peticiones[] = ['usuario' => rn07cUsuario(Rol::AuxiliarFarmacia), 'uri' => RN07C_URI."/{$s['id']}/despachar", 'cuerpo' => []];
        }
        for ($i = 0; $i < $anulan; $i++) {
            // El creador o regentes: todos con permiso para anular.
            $usuario = $i === 0 ? $s['creador'] : rn07cUsuario(Rol::RegenteFarmacia);
            $peticiones[] = ['usuario' => $usuario, 'uri' => RN07C_URI."/{$s['id']}/anular", 'cuerpo' => ['motivo' => RN07C_MOTIVO." {$ronda}-{$i}"]];
        }

        $resultados = rn07cEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn07cResumen($resultados);

        $ganadores = rn07cIndices($resultados, 200);
        expect($ganadores)->toHaveCount(1, $detalle)
            ->and(rn07cIndices($resultados, 409, 'TRANSICION_INVALIDA'))->toHaveCount($despachan + $anulan - 1, $detalle);

        $ganador = $ganadores[0];
        $fila = DB::table('traslados')->where('id', $s['id'])->first();
        $movs = rn07cMovs($s['id']);

        if ($ganador < $despachan) {
            expect($resultados[$ganador]['estado'])->toBe('EN_TRANSITO', $detalle)
                ->and($fila->estado)->toBe('EN_TRANSITO', $detalle)
                ->and((int) $fila->despachado_por_id)->toBe($peticiones[$ganador]['usuario']->id)
                ->and($fila->anulado_por_id)->toBeNull()
                ->and($fila->motivo_anulacion)->toBeNull()
                ->and($movs)->toHaveCount(2, $detalle)
                ->and(rn07cStockDe($s['existencias'][0]))->toBe($s['stocks'][0] - $s['cantidades'][0], $detalle)
                ->and(rn07cStockDe($s['existencias'][1]))->toBe($s['stocks'][1] - $s['cantidades'][1], $detalle);
        } else {
            expect($resultados[$ganador]['estado'])->toBe('ANULADO', $detalle)
                ->and($fila->estado)->toBe('ANULADO', $detalle)
                ->and((int) $fila->anulado_por_id)->toBe($peticiones[$ganador]['usuario']->id)
                ->and($fila->motivo_anulacion)->toBe($peticiones[$ganador]['cuerpo']['motivo'])
                ->and($fila->despachado_por_id)->toBeNull()
                ->and($movs)->toHaveCount(0, $detalle)
                ->and(rn07cStockDe($s['existencias'][0]))->toBe($s['stocks'][0], $detalle)
                ->and(rn07cStockDe($s['existencias'][1]))->toBe($s['stocks'][1], $detalle);
        }

        rn07cAfirmarCadena($s['existencias'][0]->id, $detalle);
        rn07cAfirmarCadena($s['existencias'][1]->id, $detalle);
    }
})->with([
    'uno despacha y uno anula' => [1, 1, 6],
    'tres despachan y tres anulan' => [3, 3, 3],
])->group('concurrencia');

it('RN-07-AC11 RN-07-AC5: N recepciones simultáneas del mismo EN_TRANSITO → una 200 RECIBIDO y N−1 409 TRANSICION_INVALIDA; una sola ENTRADA_TRASLADO por ítem', function (int $procesos, int $rondas) {
    foreach (range(1, $rondas) as $ronda) {
        $s = rn07cEscenario('EN_TRANSITO');
        $items = DB::table('traslado_items')->where('traslado_id', $s['id'])->orderBy('id')->get();
        $cuerpo = ['items' => $items->map(fn (object $it): array => ['traslado_item_id' => (int) $it->id, 'cantidad_recibida' => (int) $it->cantidad])->all()];

        $peticiones = [];
        for ($i = 0; $i < $procesos; $i++) {
            $peticiones[] = ['usuario' => rn07cUsuario($i % 2 === 0 ? Rol::AuxiliarFarmacia : Rol::RegenteFarmacia), 'uri' => RN07C_URI."/{$s['id']}/recibir", 'cuerpo' => $cuerpo];
        }
        $resultados = rn07cEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn07cResumen($resultados);

        $ganadores = rn07cIndices($resultados, 200);
        expect($ganadores)->toHaveCount(1, $detalle)
            ->and(rn07cIndices($resultados, 409, 'TRANSICION_INVALIDA'))->toHaveCount($procesos - 1, $detalle)
            ->and(DB::table('traslados')->where('id', $s['id'])->value('estado'))->toBe('RECIBIDO', $detalle);

        $entradas = rn07cMovs($s['id'])->where('tipo', 'ENTRADA_TRASLADO');
        expect($entradas)->toHaveCount(2, $detalle);
        foreach ($s['lotes'] as $k => $lote) {
            $existenciaDestino = DB::table('existencias')->where('bodega_id', $s['destino']->id)->where('lote_id', $lote->id)->get();
            expect($existenciaDestino)->toHaveCount(1, $detalle)
                ->and((int) $existenciaDestino->first()->cantidad)->toBe($s['cantidades'][$k], $detalle);
            rn07cAfirmarCadena((int) $existenciaDestino->first()->id, $detalle);
        }
    }
})->with([
    'dos recepciones' => [2, 4],
    'cinco recepciones' => [5, 2],
])->group('concurrencia');

it('RN-07-AC11 RN-07-AC10: N traslados APROBADOS distintos por el mismo stock despachados a la vez → uno 200 y N−1 409 STOCK_INSUFICIENTE que siguen APROBADO; stock final 0 y una sola salida', function (int $procesos) {
    $origen = Bodega::factory()->create();
    $destino = Bodega::factory()->create();
    $lote = rn07cLote(Producto::factory()->create());
    $existencia = rn07cStock($origen, $lote, 10);
    $creador = rn07cUsuario(Rol::AuxiliarFarmacia);
    $aprobador = rn07cUsuario(Rol::RegenteFarmacia);

    $ids = [];
    for ($i = 0; $i < $procesos; $i++) {
        $ids[] = rn07cTraslado($origen, $destino, [[$lote, 10]], $creador, $aprobador);
    }

    $resultados = rn07cEnParalelo(array_map(
        fn (int $id): array => ['usuario' => rn07cUsuario(Rol::AuxiliarFarmacia), 'uri' => RN07C_URI."/{$id}/despachar", 'cuerpo' => []],
        $ids,
    ));
    $detalle = rn07cResumen($resultados);

    $ganadores = rn07cIndices($resultados, 200);
    $sinStock = rn07cIndices($resultados, 409, 'STOCK_INSUFICIENTE');
    expect($ganadores)->toHaveCount(1, $detalle)
        ->and($sinStock)->toHaveCount($procesos - 1, $detalle)
        ->and(rn07cStockDe($existencia))->toBe(0)
        ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'SALIDA_TRASLADO')->count())->toBe(1)
        ->and(DB::table('traslados')->where('id', $ids[$ganadores[0]])->value('estado'))->toBe('EN_TRANSITO');

    foreach ($sinStock as $i) {
        expect(DB::table('traslados')->where('id', $ids[$i])->value('estado'))->toBe('APROBADO', $detalle)
            ->and(rn07cMovs($ids[$i]))->toHaveCount(0);
    }
    rn07cAfirmarCadena($existencia->id, $detalle);
})->with([
    'dos traslados' => 2,
    'seis traslados' => 6,
])->group('concurrencia');

it('RN-07-AC11: dos traslados con los mismos dos lotes en orden inverso despachados a la vez → ambos 200 sin interbloqueo (ni CONFLICTO_CONCURRENCIA) y stock descontado una vez por cada uno', function (int $rondas) {
    $creador = rn07cUsuario(Rol::AuxiliarFarmacia);
    $aprobador = rn07cUsuario(Rol::RegenteFarmacia);

    foreach (range(1, $rondas) as $ronda) {
        $origen = Bodega::factory()->create();
        $destino = Bodega::factory()->create();
        $l1 = rn07cLote(Producto::factory()->create());
        $l2 = rn07cLote(Producto::factory()->create());
        $e1 = rn07cStock($origen, $l1, 20);
        $e2 = rn07cStock($origen, $l2, 20);

        $a = rn07cTraslado($origen, $destino, [[$l1, 3], [$l2, 4]], $creador, $aprobador);
        $b = rn07cTraslado($origen, $destino, [[$l2, 5], [$l1, 6]], $creador, $aprobador);

        $resultados = rn07cEnParalelo([
            ['usuario' => rn07cUsuario(Rol::AuxiliarFarmacia), 'uri' => RN07C_URI."/{$a}/despachar", 'cuerpo' => []],
            ['usuario' => rn07cUsuario(Rol::AuxiliarFarmacia), 'uri' => RN07C_URI."/{$b}/despachar", 'cuerpo' => []],
        ]);
        $detalle = "ronda {$ronda}: ".rn07cResumen($resultados);

        foreach ($resultados as $r) {
            expect($r['status'])->toBe(200, $detalle)
                ->and($r['estado'])->toBe('EN_TRANSITO', $detalle);
        }
        expect(rn07cStockDe($e1))->toBe(20 - 3 - 6, $detalle)
            ->and(rn07cStockDe($e2))->toBe(20 - 4 - 5, $detalle)
            ->and((int) rn07cMovs($a)->sum('cantidad'))->toBe(-7)
            ->and((int) rn07cMovs($b)->sum('cantidad'))->toBe(-11);

        rn07cAfirmarCadena($e1->id, $detalle);
        rn07cAfirmarCadena($e2->id, $detalle);
    }
})->with([
    'cinco rondas' => 5,
])->group('concurrencia');

it('RN-07-AC11: BloqueoTraslado::bloquear fuera de una transacción → LogicException (el FOR UPDATE no serviría de nada) sin tocar la BD', function () {
    expect(DB::transactionLevel())->toBe(0);

    expect(fn () => app(BloqueoTraslado::class)->bloquear(new Traslado, AccionTraslado::Despachar))
        ->toThrow(LogicException::class, 'BloqueoTraslado::bloquear exige una transacción abierta.');
});
