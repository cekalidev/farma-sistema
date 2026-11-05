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
 * RN-04-AC8 — tope acumulado bajo concurrencia real.
 *
 * Procesos separados (pcntl_fork) con su propia conexión a PostgreSQL y datos con commit real (sin
 * RefreshDatabase). Todos arrancan a la vez tras la barrera de Tests\Concerns\Concurrencia y hacen
 * POST /api/v1/dispensaciones por el kernel HTTP real, cada uno con su propia Idempotency-Key.
 *
 * Lo que debe resolver la carrera es el bloqueo de los prescripcion_items (lockForUpdate) antes de
 * sumar lo ya dispensado: el stock alcanza para todos, así que sin ese bloqueo varios ganarían y el
 * total superaría lo prescrito. Se exige: exactamente los ganadores que caben en el pendiente (201),
 * el resto 422 CANTIDAD_EXCEDE_PRESCRITO con el pendiente que queda, total dispensado ≤ prescrito,
 * stock descontado solo por los ganadores y kardex encadenado. Un 409 (STOCK_INSUFICIENTE o
 * CONFLICTO_CONCURRENCIA) o un 500 incumplen AC8.
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-04 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Un hijo por petición; todos arrancan a la vez. Devuelve por hijo: status, código de error,
 * detalles del error e id creado.
 *
 * @param  list<array{usuario: User, clave: string, cuerpo: array<string, mixed>}>  $peticiones
 * @return list<array{status: int|string, codigo: ?string, detalles: mixed, id: ?int}>
 */
function rn04ConcEnParalelo(array $peticiones): array
{
    $peticiones = array_values($peticiones);

    $crudos = Concurrencia::enParalelo(
        count($peticiones),
        function (int $i) use ($peticiones): string {
            $resultado = ['status' => 'sin_resultado', 'codigo' => null, 'detalles' => null, 'id' => null];
            try {
                $r = test()->postJson('/api/v1/dispensaciones', $peticiones[$i]['cuerpo'], ['Idempotency-Key' => $peticiones[$i]['clave']]);
                $resultado = [
                    'status' => $r->getStatusCode(),
                    'codigo' => $r->json('error.codigo'),
                    'detalles' => $r->json('error.detalles'),
                    'id' => $r->json('data.id'),
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
function rn04ConcResumen(array $resultados): string
{
    return implode(', ', array_map(
        fn (array $r): string => $r['status'].'|'.($r['codigo'] ?? '').'|pendiente='.json_encode($r['detalles']['pendiente'] ?? null).'|id='.($r['id'] ?? '-'),
        $resultados,
    ));
}

/** Lote nuevo con `cantidad` en la bodega, por una ENTRADA real con commit. */
function rn04ConcStock(Bodega $bodega, Producto $producto, int $cantidad): Existencia
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

function rn04ConcStockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/** Suma dispensada del ítem en estados que cuentan para el tope (CONFIRMADA + PENDIENTE_AUTORIZACION). */
function rn04ConcDispensado(PrescripcionItem $item): int
{
    return (int) DB::table('dispensacion_items')
        ->join('dispensaciones', 'dispensaciones.id', '=', 'dispensacion_items.dispensacion_id')
        ->where('dispensacion_items.prescripcion_item_id', $item->id)
        ->whereIn('dispensaciones.estado', ['CONFIRMADA', 'PENDIENTE_AUTORIZACION'])
        ->sum('dispensacion_items.cantidad');
}

/** Cadena de saldos de la existencia sin huecos (RN-06-AC2). */
function rn04ConcAfirmarCadena(Existencia $existencia, string $contexto): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} movimiento #{$i}: hueco o saldo duplicado")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn04ConcStockDe($existencia), "{$contexto}: el kardex no cuadra con la existencia");
}

/**
 * @param  list<array{0: PrescripcionItem, 1: int}>  $items
 * @return array<string, mixed>
 */
function rn04ConcCuerpo(Prescripcion $prescripcion, Bodega $bodega, array $items): array
{
    return [
        'paciente_id' => $prescripcion->paciente_id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => $bodega->id,
        'items' => array_map(fn (array $i): array => ['prescripcion_item_id' => $i[0]->id, 'cantidad' => $i[1]], $items),
    ];
}

/** Usuarios dispensadores alternando auxiliar y regente (el tope es por ítem, no por usuario). */
function rn04ConcUsuarios(int $n): array
{
    $usuarios = [];
    for ($i = 0; $i < $n; $i++) {
        $usuarios[] = User::factory()->create(['rol' => $i % 2 === 0 ? Rol::AuxiliarFarmacia : Rol::RegenteFarmacia]);
    }

    return $usuarios;
}

it('RN-04-AC8: N procesos piden a la vez sobre el mismo ítem con stock de sobra → solo ganan los que caben en el pendiente (201) y el resto 422 CANTIDAD_EXCEDE_PRESCRITO con el pendiente restante; nunca se supera lo prescrito', function (int $procesos, int $rondas, int $prescrito, int $previa, int $pedido, int $stockInicial) {
    $usuarios = rn04ConcUsuarios($procesos);
    $pendiente = $prescrito - $previa;
    $ganadores = min($procesos, intdiv($pendiente, $pedido));
    $pendienteFinal = $pendiente - $ganadores * $pedido;

    foreach (range(1, $rondas) as $ronda) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn04ConcStock($bodega, $producto, $stockInicial);
        $prescripcion = Prescripcion::factory()->create();
        $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => $prescrito]);

        if ($previa > 0) {
            // Dispensación previa real (con commit) que deja el pendiente indicado.
            Sanctum::actingAs($usuarios[0]);
            test()->postJson('/api/v1/dispensaciones', rn04ConcCuerpo($prescripcion, $bodega, [[$item, $previa]]), ['Idempotency-Key' => "rn04-ac8-previa-{$ronda}"])
                ->assertCreated();
            app('auth')->forgetGuards();
        }
        $stockAntes = rn04ConcStockDe($existencia);
        expect($stockAntes)->toBe($stockInicial - $previa)
            // El stock alcanza para al menos un ganador más de los que caben en el tope: sin el bloqueo
            // de prescripcion_items, el stock no impediría superar lo prescrito.
            ->and($stockAntes)->toBeGreaterThanOrEqual(min($procesos, $ganadores + 1) * $pedido);

        $peticiones = [];
        for ($i = 0; $i < $procesos; $i++) {
            $peticiones[] = [
                'usuario' => $usuarios[$i],
                'clave' => "rn04-ac8-{$ronda}-{$i}",
                'cuerpo' => rn04ConcCuerpo($prescripcion, $bodega, [[$item, $pedido]]),
            ];
        }

        $resultados = rn04ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn04ConcResumen($resultados);

        $exitos = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] === 201));
        $rechazos = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] !== 201));
        expect($exitos)->toHaveCount($ganadores, $detalle)
            ->and($rechazos)->toHaveCount($procesos - $ganadores, $detalle);

        foreach ($exitos as $r) {
            expect($r['id'])->toBeInt($detalle);
        }
        foreach ($rechazos as $r) {
            expect($r['status'])->toBe(422, $detalle)
                ->and($r['codigo'])->toBe('CANTIDAD_EXCEDE_PRESCRITO', $detalle)
                ->and($r['detalles']['prescripcion_item_id'] ?? null)->toBe($item->id, $detalle)
                ->and($r['detalles']['pendiente'] ?? null)->toBe($pendienteFinal, $detalle)
                ->and($r['detalles']['solicitado'] ?? null)->toBe($pedido, $detalle);
        }

        $idsGanadores = array_map(fn (array $r): int => $r['id'], $exitos);
        $salidasNuevas = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)
            ->where('tipo', 'SALIDA_DISPENSACION')->whereIn('documento_id', $idsGanadores ?: [0])->get();

        expect(rn04ConcDispensado($item))->toBe($previa + $ganadores * $pedido, $detalle)
            ->and(rn04ConcDispensado($item))->toBeLessThanOrEqual($prescrito, $detalle)
            ->and(DB::table('dispensaciones')->where('prescripcion_id', $prescripcion->id)->count())->toBe(($previa > 0 ? 1 : 0) + $ganadores, "{$detalle}: quedaron dispensaciones de perdedores")
            ->and(DB::table('dispensacion_items')->where('prescripcion_item_id', $item->id)->count())->toBe(($previa > 0 ? 1 : 0) + $ganadores, $detalle)
            ->and($salidasNuevas)->toHaveCount($ganadores, $detalle)
            ->and((int) $salidasNuevas->sum('cantidad'))->toBe(-$ganadores * $pedido, $detalle)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(($previa > 0 ? 1 : 0) + $ganadores, $detalle)
            ->and(rn04ConcStockDe($existencia))->toBe($stockAntes - $ganadores * $pedido, $detalle);

        rn04ConcAfirmarCadena($existencia, $detalle);
    }
})->with([
    'spec: 2 procesos piden 5 sobre pendiente 5 (10 prescritos, 5 ya dispensados, stock para 10) × 5 rondas' => [2, 5, 10, 5, 5, 15],
    '6 procesos piden 5 sobre pendiente 5 × 3 rondas' => [6, 3, 10, 5, 5, 15],
    '4 procesos piden 5 sobre un ítem de 5 sin dispensaciones previas (stock 10) × 3 rondas' => [4, 3, 5, 0, 5, 10],
    '6 procesos piden 3 sobre pendiente 5 → uno gana, el resto pendiente 2 × 3 rondas' => [6, 3, 10, 5, 3, 15],
    '5 procesos piden 1 sobre pendiente 3 → ganan exactamente 3 × 3 rondas' => [5, 3, 10, 7, 1, 20],
])->group('concurrencia');

it('RN-04-AC8: dos ítems de la misma prescripción pedidos en órdenes opuestos a la vez → una sola 201, el resto 422 CANTIDAD_EXCEDE_PRESCRITO (sin interbloqueo ni 409); ningún ítem supera su tope', function () {
    $procesos = 6;
    $usuarios = rn04ConcUsuarios($procesos);

    foreach (range(1, 3) as $ronda) {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $e1 = rn04ConcStock($bodega, $p1, 50);
        $e2 = rn04ConcStock($bodega, $p2, 50);
        $prescripcion = Prescripcion::factory()->create();
        $a = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $p1->id, 'cantidad' => 5]);
        $b = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $p2->id, 'cantidad' => 5]);

        $peticiones = [];
        for ($i = 0; $i < $procesos; $i++) {
            $items = $i % 2 === 0 ? [[$a, 5], [$b, 5]] : [[$b, 5], [$a, 5]];
            $peticiones[] = [
                'usuario' => $usuarios[$i],
                'clave' => "rn04-ac8-orden-{$ronda}-{$i}",
                'cuerpo' => rn04ConcCuerpo($prescripcion, $bodega, $items),
            ];
        }

        $resultados = rn04ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn04ConcResumen($resultados);

        $exitos = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] === 201));
        expect($exitos)->toHaveCount(1, $detalle);
        foreach ($resultados as $r) {
            if ($r['status'] === 201) {
                continue;
            }
            expect($r['status'])->toBe(422, $detalle)
                ->and($r['codigo'])->toBe('CANTIDAD_EXCEDE_PRESCRITO', $detalle)
                ->and($r['detalles']['prescripcion_item_id'] ?? null)->toBeIn([$a->id, $b->id], $detalle)
                ->and($r['detalles']['pendiente'] ?? null)->toBe(0, $detalle)
                ->and($r['detalles']['solicitado'] ?? null)->toBe(5, $detalle);
        }

        expect(rn04ConcDispensado($a))->toBe(5, $detalle)
            ->and(rn04ConcDispensado($b))->toBe(5, $detalle)
            ->and(DB::table('dispensaciones')->where('prescripcion_id', $prescripcion->id)->count())->toBe(1, $detalle)
            ->and(rn04ConcStockDe($e1))->toBe(45, $detalle)
            ->and(rn04ConcStockDe($e2))->toBe(45, $detalle)
            ->and(DB::table('movimientos_kardex')->whereIn('existencia_id', [$e1->id, $e2->id])->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(2, $detalle);

        rn04ConcAfirmarCadena($e1, $detalle);
        rn04ConcAfirmarCadena($e2, $detalle);
    }
})->group('concurrencia');
