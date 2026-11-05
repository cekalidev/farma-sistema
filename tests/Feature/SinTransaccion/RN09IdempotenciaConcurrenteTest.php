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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Concurrencia;

/*
 * RN-09-AC6 — reintentos simultáneos con la misma Idempotency-Key.
 *
 * Procesos separados (pcntl_fork) con su propia conexión a PostgreSQL y datos con commit real (sin
 * RefreshDatabase). Todos arrancan a la vez tras la barrera de Tests\Concerns\Concurrencia y hacen
 * POST /api/v1/dispensaciones por el kernel HTTP real.
 *
 * Lo que debe resolver la carrera es UNIQUE(dispensado_por_id, idempotency_key) (no un exists()
 * previo): exactamente una dispensación, todas las respuestas 201 con el mismo id y el mismo cuerpo,
 * exactamente N−1 con `Idempotent-Replayed: true`, y el stock descontado una sola vez. Un
 * CONFLICTO_CONCURRENCIA (S-48) o un STOCK_INSUFICIENTE para un reintento idéntico incumplen AC6.
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-09 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Un hijo por petición; todos arrancan a la vez. Devuelve por hijo: status, código de error, id,
 * cabecera Idempotent-Replayed y el cuerpo JSON completo.
 *
 * @param  list<array{usuario: User, clave: string, cuerpo: array<string, mixed>}>  $peticiones
 * @return list<array{status: int|string, codigo: ?string, id: ?int, replayed: ?string, json: mixed}>
 */
function rn09ConcEnParalelo(array $peticiones): array
{
    $peticiones = array_values($peticiones);

    $crudos = Concurrencia::enParalelo(
        count($peticiones),
        function (int $i) use ($peticiones): string {
            $resultado = ['status' => 'sin_resultado', 'codigo' => null, 'id' => null, 'replayed' => null, 'json' => null];
            try {
                $r = test()->postJson('/api/v1/dispensaciones', $peticiones[$i]['cuerpo'], ['Idempotency-Key' => $peticiones[$i]['clave']]);
                $resultado = [
                    'status' => $r->getStatusCode(),
                    'codigo' => $r->json('error.codigo'),
                    'id' => $r->json('data.id'),
                    'replayed' => $r->headers->get('Idempotent-Replayed'),
                    'json' => $r->json(),
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
function rn09ConcResumen(array $resultados): string
{
    return implode(', ', array_map(
        fn (array $r): string => $r['status'].'|'.($r['codigo'] ?? '').'|id='.($r['id'] ?? '-').'|replayed='.($r['replayed'] ?? '-'),
        $resultados,
    ));
}

/** Lote nuevo con `cantidad` en la bodega, por una ENTRADA real con commit. */
function rn09ConcStock(Bodega $bodega, Producto $producto, int $cantidad): Existencia
{
    $lote = Lote::factory()->create([
        'producto_id' => $producto->id,
        'fecha_vencimiento' => Negocio::hoy()->addDays(200)->toDateString(),
    ]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

    DB::transaction(function () use ($existencia, $cantidad, $regente): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
    });

    return $existencia->refresh();
}

function rn09ConcStockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Escenario con commit: bodega, producto con `stock` y una prescripción con un ítem holgado.
 *
 * @return array{bodega: Bodega, producto: Producto, existencia: Existencia, prescripcion: Prescripcion, item: PrescripcionItem}
 */
function rn09ConcEscenario(int $stock): array
{
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->create();
    $existencia = rn09ConcStock($bodega, $producto, $stock);
    $prescripcion = Prescripcion::factory()->create();
    $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 1000]);

    return compact('bodega', 'producto', 'existencia', 'prescripcion', 'item');
}

/**
 * @param  array<string, mixed>  $s
 * @return array<string, mixed>
 */
function rn09ConcCuerpo(array $s, int $cantidad): array
{
    return [
        'paciente_id' => $s['prescripcion']->paciente_id,
        'prescripcion_id' => $s['prescripcion']->id,
        'bodega_id' => $s['bodega']->id,
        'items' => [['prescripcion_item_id' => $s['item']->id, 'cantidad' => $cantidad]],
    ];
}

/** Cadena de saldos de la existencia sin huecos (RN-06-AC2). */
function rn09ConcAfirmarCadena(Existencia $existencia, string $contexto): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} movimiento #{$i}: hueco o saldo duplicado")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn09ConcStockDe($existencia), "{$contexto}: el kardex no cuadra con la existencia");
}

it('RN-09-AC6: N procesos con la misma clave y el mismo cuerpo → una sola dispensación; todas 201 con el mismo id y el mismo cuerpo, exactamente N−1 con Idempotent-Replayed: true; stock descontado una vez', function (int $procesos, int $rondas, int $stock, int $cantidad) {
    $usuario = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);

    foreach (range(1, $rondas) as $ronda) {
        $s = rn09ConcEscenario($stock);
        $clave = "rn09-ac6-{$procesos}-{$ronda}";
        $cuerpo = rn09ConcCuerpo($s, $cantidad);

        $peticiones = array_fill(0, $procesos, ['usuario' => $usuario, 'clave' => $clave, 'cuerpo' => $cuerpo]);
        $resultados = rn09ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn09ConcResumen($resultados);

        foreach ($resultados as $r) {
            expect($r['status'])->toBe(201, $detalle)
                ->and($r['codigo'])->toBeNull($detalle);
        }

        $ids = array_values(array_unique(array_map(fn (array $r): mixed => $r['id'], $resultados)));
        expect($ids)->toHaveCount(1, $detalle)
            ->and($ids[0])->toBeInt($detalle);
        $id = $ids[0];

        $reproducidas = array_filter($resultados, fn (array $r): bool => $r['replayed'] === 'true');
        $originales = array_filter($resultados, fn (array $r): bool => $r['replayed'] === null);
        expect($reproducidas)->toHaveCount($procesos - 1, $detalle)
            ->and($originales)->toHaveCount(1, $detalle);

        $cuerpoOriginal = array_values($originales)[0]['json'];
        foreach ($reproducidas as $r) {
            expect($r['json'])->toBe($cuerpoOriginal, "{$detalle}: la reproducción no es el cuerpo original");
        }

        $dispensaciones = DB::table('dispensaciones')->where('bodega_id', $s['bodega']->id)->get();
        $salidas = DB::table('movimientos_kardex')->where('existencia_id', $s['existencia']->id)->where('tipo', 'SALIDA_DISPENSACION')->get();

        expect($dispensaciones)->toHaveCount(1, $detalle)
            ->and((int) $dispensaciones[0]->id)->toBe($id)
            ->and($dispensaciones[0]->idempotency_key)->toBe($clave)
            ->and((int) $dispensaciones[0]->dispensado_por_id)->toBe($usuario->id)
            ->and($dispensaciones[0]->request_hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and(DB::table('dispensacion_items')->where('dispensacion_id', $id)->count())->toBe(1)
            ->and(DB::table('dispensacion_items')->where('producto_id', $s['producto']->id)->count())->toBe(1, "{$detalle}: quedaron ítems de perdedores")
            ->and(DB::table('dispensacion_detalles')->where('existencia_id', $s['existencia']->id)->count())->toBe(1)
            ->and($salidas)->toHaveCount(1, $detalle)
            ->and((int) $salidas[0]->cantidad)->toBe(-$cantidad)
            ->and((int) $salidas[0]->documento_id)->toBe($id)
            ->and(rn09ConcStockDe($s['existencia']))->toBe($stock - $cantidad, $detalle);

        rn09ConcAfirmarCadena($s['existencia'], $detalle);
    }
})->with([
    '2 procesos × 5 rondas, stock holgado' => [2, 5, 10, 2],
    '8 procesos × 3 rondas, stock holgado' => [8, 3, 10, 2],
    '6 procesos × 3 rondas, exactamente la última unidad (ninguno da 409)' => [6, 3, 1, 1],
])->group('concurrencia');

it('RN-09-AC6 RN-09-AC2: misma clave, mismo usuario y dos cuerpos distintos a la vez → una sola dispensación; quien coincide con el cuerpo ganador recibe 201 con su id y el resto 422 IDEMPOTENCY_KEY_REUTILIZADA', function () {
    $usuario = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

    foreach (range(1, 3) as $ronda) {
        $s = rn09ConcEscenario(20);
        $clave = "rn09-ac6-mezcla-{$ronda}";
        $cuerpos = [2 => rn09ConcCuerpo($s, 2), 3 => rn09ConcCuerpo($s, 3)];

        $peticiones = [];
        for ($i = 0; $i < 6; $i++) {
            $peticiones[] = ['usuario' => $usuario, 'clave' => $clave, 'cuerpo' => $cuerpos[$i % 2 === 0 ? 2 : 3]];
        }

        $resultados = rn09ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn09ConcResumen($resultados);

        $dispensaciones = DB::table('dispensaciones')->where('bodega_id', $s['bodega']->id)->get();
        expect($dispensaciones)->toHaveCount(1, $detalle);
        $id = (int) $dispensaciones[0]->id;
        $ganadora = (int) DB::table('dispensacion_items')->where('dispensacion_id', $id)->value('cantidad');
        expect($ganadora)->toBeIn([2, 3], $detalle);

        $originales = 0;
        foreach ($resultados as $i => $r) {
            $mismoCuerpo = ($i % 2 === 0 ? 2 : 3) === $ganadora;
            if ($mismoCuerpo) {
                expect($r['status'])->toBe(201, $detalle)
                    ->and($r['id'])->toBe($id, $detalle);
                $originales += $r['replayed'] === null ? 1 : 0;
                expect($r['replayed'])->toBeIn([null, 'true'], $detalle);
            } else {
                expect($r['status'])->toBe(422, $detalle)
                    ->and($r['codigo'])->toBe('IDEMPOTENCY_KEY_REUTILIZADA', $detalle);
            }
        }

        expect($originales)->toBe(1, $detalle)
            ->and(rn09ConcStockDe($s['existencia']))->toBe(20 - $ganadora, $detalle)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $s['existencia']->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(1, $detalle);
        rn09ConcAfirmarCadena($s['existencia'], $detalle);
    }
})->group('concurrencia');

it('RN-09-AC6 RN-09-AC5: usuarios distintos con la misma clave a la vez → cada uno su dispensación (ninguna reproducida) y el stock se descuenta por cada una', function () {
    foreach (range(1, 3) as $ronda) {
        $s = rn09ConcEscenario(20);
        $cuerpo = rn09ConcCuerpo($s, 2);

        $peticiones = [];
        for ($i = 0; $i < 4; $i++) {
            $peticiones[] = [
                'usuario' => User::factory()->create(['rol' => $i % 2 === 0 ? Rol::AuxiliarFarmacia : Rol::RegenteFarmacia]),
                'clave' => "rn09-ac6-compartida-{$ronda}",
                'cuerpo' => $cuerpo,
            ];
        }

        $resultados = rn09ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn09ConcResumen($resultados);

        foreach ($resultados as $r) {
            expect($r['status'])->toBe(201, $detalle)
                ->and($r['replayed'])->toBeNull($detalle);
        }
        $ids = array_values(array_unique(array_map(fn (array $r): mixed => $r['id'], $resultados)));

        expect($ids)->toHaveCount(4, $detalle)
            ->and(DB::table('dispensaciones')->where('bodega_id', $s['bodega']->id)->count())->toBe(4)
            ->and(DB::table('dispensaciones')->where('bodega_id', $s['bodega']->id)->distinct()->count('dispensado_por_id'))->toBe(4)
            ->and(rn09ConcStockDe($s['existencia']))->toBe(12, $detalle)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $s['existencia']->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(4);
        rn09ConcAfirmarCadena($s['existencia'], $detalle);
    }
})->group('concurrencia');
