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
 * RN-03-AC3 [OBLIGATORIO] — la última unidad con solicitudes simultáneas.
 *
 * Procesos separados (pcntl_fork), cada uno con su propia conexión a PostgreSQL, datos con commit
 * real (sin RefreshDatabase ni transacción envolvente). Cada hijo espera una barrera común (flock)
 * y hace POST /api/v1/dispensaciones por el kernel HTTP real (Form Request → servicio → BD).
 *
 * Cada hijo usa su PROPIA prescripción (otro paciente): la única fila que comparten es la
 * existencia, así que solo su bloqueo puede serializarlos (un bloqueo futuro de prescripcion_items,
 * RN-04, no enmascara la ausencia del bloqueo de stock).
 *
 * También RN-03-AC5 sin transacción envolvente (commit/rollback reales, no un savepoint).
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-03 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    // migrate:fresh borra tablas (y sus triggers) pero no funciones.
    DB::unprepared('DROP FUNCTION IF EXISTS rn03_conc_falla() CASCADE');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Lanza un hijo por petición; todos arrancan a la vez tras la barrera en dos fases de Tests\Concerns\Concurrencia.
 * Devuelve, por hijo: status, codigo de error, detalles del error, id y detalles de la dispensación.
 *
 * @param  list<array{usuario: User, cuerpo: array<string, mixed>}>  $peticiones
 * @return list<array{status: int|string, codigo: ?string, error: array<string, mixed>, id: ?int, detalles: list<array{lote_id: int, cantidad: int}>}>
 */
function rn03ConcEnParalelo(array $peticiones): array
{
    $peticiones = array_values($peticiones);

    $crudos = Concurrencia::enParalelo(
        count($peticiones),
        function (int $i) use ($peticiones): string {
            $resultado = ['status' => 'sin_resultado', 'codigo' => null, 'error' => [], 'id' => null, 'detalles' => []];
            try {
                $respuesta = test()->postJson('/api/v1/dispensaciones', $peticiones[$i]['cuerpo'], cabecerasDispensacion());
                $resultado = [
                    'status' => $respuesta->getStatusCode(),
                    'codigo' => $respuesta->json('error.codigo'),
                    'error' => (array) ($respuesta->json('error.detalles') ?? []),
                    'id' => $respuesta->json('data.id'),
                    'detalles' => array_map(
                        fn (array $d): array => ['lote_id' => (int) $d['lote_id'], 'cantidad' => (int) $d['cantidad']],
                        (array) ($respuesta->json('data.detalles') ?? []),
                    ),
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

/** Resumen legible para los mensajes de fallo. @param list<array<string, mixed>> $resultados */
function rn03ConcResumen(array $resultados): string
{
    return implode(', ', array_map(fn (array $r): string => $r['status'].'|'.($r['codigo'] ?? ''), $resultados));
}

/** Lote nuevo (vence en `dias`) con `cantidad` en la bodega, por una ENTRADA real con commit. */
function rn03ConcStock(Bodega $bodega, Producto $producto, int $dias, int $cantidad): Existencia
{
    $lote = Lote::factory()->create([
        'producto_id' => $producto->id,
        'fecha_vencimiento' => Negocio::hoy()->addDays($dias)->toDateString(),
    ]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

    DB::transaction(function () use ($existencia, $cantidad, $regente): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
    });

    return $existencia->refresh();
}

/**
 * Cuerpo con prescripción propia (paciente distinto) y un ítem holgado por producto.
 *
 * @param  list<array{0: int, 1: int}>  $items  [[producto_id, cantidad], …]
 * @return array<string, mixed>
 */
function rn03ConcCuerpo(int $bodegaId, array $items): array
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

/** Auxiliar y regente alternados (ambos dispensan). */
function rn03ConcUsuario(int $i): User
{
    return User::factory()->create(['rol' => $i % 2 === 0 ? Rol::AuxiliarFarmacia : Rol::RegenteFarmacia]);
}

function rn03ConcStockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/** Cadena de saldos de la existencia sin huecos, en orden (created_at, id) y en orden de id. */
function rn03ConcAfirmarCadena(Existencia $existencia, string $contexto): void
{
    $stock = rn03ConcStockDe($existencia);

    foreach ([['created_at', 'id'], ['id']] as $columnas) {
        $consulta = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id);
        foreach ($columnas as $columna) {
            $consulta->orderBy($columna);
        }

        $saldo = 0;
        foreach ($consulta->get() as $i => $m) {
            expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} existencia {$existencia->id} movimiento #{$i}: hueco o saldo duplicado")
                ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
                ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
            $saldo = (int) $m->saldo_posterior;
        }
        expect($saldo)->toBe($stock, "{$contexto}: el kardex no cuadra con la existencia");
    }
}

/**
 * Afirma el reparto ganadores / perdedores y devuelve los ganadores.
 *
 * @param  list<array<string, mixed>>  $resultados
 * @return list<array<string, mixed>>
 */
function rn03ConcAfirmarReparto(array $resultados, int $exitos, int $solicitado, int $disponibleAlFinal, string $contexto): array
{
    $detalle = $contexto.': '.rn03ConcResumen($resultados);
    $ganadores = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] === 201));
    $perdedores = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] !== 201));

    expect($ganadores)->toHaveCount($exitos, $detalle)
        ->and($perdedores)->toHaveCount(count($resultados) - $exitos, $detalle);

    foreach ($perdedores as $r) {
        expect($r['status'])->toBe(409, $detalle)
            ->and($r['codigo'])->toBe('STOCK_INSUFICIENTE', $detalle)
            ->and($r['error']['solicitado'] ?? null)->toBe($solicitado, $detalle)
            ->and($r['error']['disponible'] ?? null)->toBe($disponibleAlFinal, $detalle);
    }

    return $ganadores;
}

it('RN-03-AC3: con exactamente 1 unidad y solicitudes simultáneas de 1 desde procesos y conexiones distintas → una 201 y el resto 409 STOCK_INSUFICIENTE; stock 0, un SALIDA_DISPENSACION y una dispensación', function (int $procesos, int $rondas) {
    foreach (range(1, $rondas) as $ronda) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $existencia = rn03ConcStock($bodega, $producto, 200, 1);

        $peticiones = [];
        for ($i = 0; $i < $procesos; $i++) {
            $peticiones[] = ['usuario' => rn03ConcUsuario($i), 'cuerpo' => rn03ConcCuerpo($bodega->id, [[$producto->id, 1]])];
        }

        $resultados = rn03ConcEnParalelo($peticiones);
        [$ganador] = rn03ConcAfirmarReparto($resultados, 1, 1, 0, "ronda {$ronda}");

        $salidas = DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)
            ->where('tipo', 'SALIDA_DISPENSACION')->get();

        expect(rn03ConcStockDe($existencia))->toBe(0, "ronda {$ronda}")
            ->and($salidas)->toHaveCount(1)
            ->and((int) $salidas[0]->cantidad)->toBe(-1)
            ->and((int) $salidas[0]->saldo_anterior)->toBe(1)
            ->and((int) $salidas[0]->saldo_posterior)->toBe(0)
            ->and((int) $salidas[0]->documento_id)->toBe($ganador['id'])
            ->and(DB::table('dispensaciones')->where('bodega_id', $bodega->id)->pluck('id')->map(fn ($id): int => (int) $id)->all())->toBe([$ganador['id']])
            ->and(DB::table('dispensacion_items')->where('dispensacion_id', $ganador['id'])->count())->toBe(1)
            ->and(DB::table('dispensacion_items')->where('producto_id', $producto->id)->count())->toBe(1, 'los perdedores no dejan ítems')
            ->and(DB::table('dispensacion_detalles')->where('existencia_id', $existencia->id)->count())->toBe(1)
            ->and($ganador['detalles'])->toBe([['lote_id' => $existencia->lote_id, 'cantidad' => 1]])
            ->and((int) DB::table('existencias')->where('cantidad', '<', 0)->count())->toBe(0);

        rn03ConcAfirmarCadena($existencia, "ronda {$ronda}");
    }
})->with([
    '2 procesos × 6 rondas (spec)' => [2, 6],
    '8 procesos × 3 rondas' => [8, 3],
])->group('concurrencia');

it('RN-03-AC3 dos lotes: 6 procesos piden 1 sobre stock 3 (lote que vence antes: 1 u., creado después; otro: 2 u.) → exactamente 3 éxitos, stock 0, kardex sin huecos y salidas en orden FEFO', function () {
    foreach (range(1, 3) as $ronda) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $tarde = rn03ConcStock($bodega, $producto, 200, 2);
        $pronto = rn03ConcStock($bodega, $producto, 20, 1); // id mayor, vence antes

        $peticiones = [];
        for ($i = 0; $i < 6; $i++) {
            $peticiones[] = ['usuario' => rn03ConcUsuario($i), 'cuerpo' => rn03ConcCuerpo($bodega->id, [[$producto->id, 1]])];
        }

        $resultados = rn03ConcEnParalelo($peticiones);
        $ganadores = rn03ConcAfirmarReparto($resultados, 3, 1, 0, "ronda {$ronda}");

        $salidas = DB::table('movimientos_kardex')->where('producto_id', $producto->id)
            ->where('tipo', 'SALIDA_DISPENSACION')->orderBy('id')->get();

        // FEFO en el orden real de las salidas: la primera serializada toma el lote que vence antes.
        expect($salidas->map(fn (object $m): int => (int) $m->lote_id)->all())
            ->toBe([$pronto->lote_id, $tarde->lote_id, $tarde->lote_id], "ronda {$ronda}")
            ->and($salidas->map(fn (object $m): int => (int) $m->cantidad)->all())->toBe([-1, -1, -1])
            ->and(rn03ConcStockDe($pronto))->toBe(0)
            ->and(rn03ConcStockDe($tarde))->toBe(0)
            ->and(DB::table('dispensaciones')->where('bodega_id', $bodega->id)->count())->toBe(3)
            ->and(DB::table('dispensacion_detalles')->where('bodega_id', $bodega->id)->count())->toBe(3);

        $ids = array_map(fn (array $g): int => $g['id'], $ganadores);
        sort($ids);
        expect($salidas->map(fn (object $m): int => (int) $m->documento_id)->sort()->values()->all())->toBe($ids);
        foreach ($ganadores as $g) {
            expect($g['detalles'])->toHaveCount(1)
                ->and($g['detalles'][0]['cantidad'])->toBe(1);
        }

        rn03ConcAfirmarCadena($pronto, "ronda {$ronda}");
        rn03ConcAfirmarCadena($tarde, "ronda {$ronda}");
    }
})->group('concurrencia');

it('RN-03-AC3 cruce de lotes: 4 procesos piden 2 sobre 1 + 2 unidades → una sola 201 que toma 1 de cada lote en orden FEFO; el resto 409 con disponible 1 y queda 1', function () {
    foreach (range(1, 3) as $ronda) {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->create();
        $tarde = rn03ConcStock($bodega, $producto, 200, 2);
        $pronto = rn03ConcStock($bodega, $producto, 20, 1);

        $peticiones = [];
        for ($i = 0; $i < 4; $i++) {
            $peticiones[] = ['usuario' => rn03ConcUsuario($i), 'cuerpo' => rn03ConcCuerpo($bodega->id, [[$producto->id, 2]])];
        }

        $resultados = rn03ConcEnParalelo($peticiones);
        [$ganador] = rn03ConcAfirmarReparto($resultados, 1, 2, 1, "ronda {$ronda}");

        expect($ganador['detalles'])->toBe([
            ['lote_id' => $pronto->lote_id, 'cantidad' => 1],
            ['lote_id' => $tarde->lote_id, 'cantidad' => 1],
        ])
            ->and(rn03ConcStockDe($pronto))->toBe(0)
            ->and(rn03ConcStockDe($tarde))->toBe(1)
            ->and(DB::table('movimientos_kardex')->where('producto_id', $producto->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(2)
            ->and(DB::table('dispensaciones')->where('bodega_id', $bodega->id)->count())->toBe(1);

        rn03ConcAfirmarCadena($pronto, "ronda {$ronda}");
        rn03ConcAfirmarCadena($tarde, "ronda {$ronda}");
    }
})->group('concurrencia');

it('RN-03-AC3 interbloqueo: 6 procesos piden 1 de P1 y 1 de P2 con los ítems en orden opuesto → sin 500 por deadlock: exactamente 2 éxitos y 4 × 409, todo el stock en 0', function () {
    foreach (range(1, 3) as $ronda) {
        $bodega = Bodega::factory()->create();
        $p1 = Producto::factory()->create();
        $p2 = Producto::factory()->create();
        $existencias = [
            rn03ConcStock($bodega, $p2, 30, 1),
            rn03ConcStock($bodega, $p1, 30, 1),
            rn03ConcStock($bodega, $p2, 300, 1),
            rn03ConcStock($bodega, $p1, 300, 1),
        ];

        $peticiones = [];
        for ($i = 0; $i < 6; $i++) {
            $items = $i % 2 === 0 ? [[$p1->id, 1], [$p2->id, 1]] : [[$p2->id, 1], [$p1->id, 1]];
            $peticiones[] = ['usuario' => rn03ConcUsuario($i), 'cuerpo' => rn03ConcCuerpo($bodega->id, $items)];
        }

        $resultados = rn03ConcEnParalelo($peticiones);
        $detalle = "ronda {$ronda}: ".rn03ConcResumen($resultados);
        $ganadores = array_values(array_filter($resultados, fn (array $r): bool => $r['status'] === 201));

        expect($ganadores)->toHaveCount(2, $detalle);
        foreach ($resultados as $r) {
            expect([$r['status'], $r['codigo']])->toBeIn([[201, null], [409, 'STOCK_INSUFICIENTE']], $detalle);
        }
        foreach ($ganadores as $g) {
            expect($g['detalles'])->toHaveCount(2, $detalle);
        }

        foreach ($existencias as $e) {
            expect(rn03ConcStockDe($e))->toBe(0, $detalle);
            rn03ConcAfirmarCadena($e, $detalle);
        }
        expect(DB::table('movimientos_kardex')->where('bodega_id', $bodega->id)->where('tipo', 'SALIDA_DISPENSACION')->count())->toBe(4)
            ->and(DB::table('dispensaciones')->where('bodega_id', $bodega->id)->count())->toBe(2);
    }
})->group('concurrencia');

it('RN-03-AC5 sin transacción envolvente: si el kardex falla después de descontar, el ROLLBACK real deja el stock original y nada nuevo confirmado; sin el fallo la misma dispensación funciona', function () {
    $bodega = Bodega::factory()->create();
    $producto = Producto::factory()->create();
    $existencia = rn03ConcStock($bodega, $producto, 200, 3);
    $usuario = rn03ConcUsuario(0);
    $cuerpo = rn03ConcCuerpo($bodega->id, [[$producto->id, 1]]);

    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION rn03_conc_falla() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            -- Solo si la existencia YA quedó descontada en esta transacción (fallo después de descontar).
            IF (SELECT e.cantidad FROM existencias e WHERE e.id = NEW.existencia_id) = NEW.saldo_posterior THEN
                RAISE EXCEPTION 'fallo inyectado por el test RN-03-AC5';
            END IF;
            RETURN NEW;
        END;
        $$;
        CREATE TRIGGER rn03_conc_falla BEFORE INSERT ON movimientos_kardex
            FOR EACH ROW EXECUTE FUNCTION rn03_conc_falla();
        SQL);

    $contar = fn (): array => [
        rn03ConcStockDe($existencia),
        DB::table('movimientos_kardex')->count(),
        DB::table('dispensaciones')->count(),
        DB::table('dispensacion_items')->count(),
        DB::table('dispensacion_detalles')->count(),
    ];
    $antes = $contar();

    Sanctum::actingAs($usuario);
    $r = $this->postJson('/api/v1/dispensaciones', $cuerpo, cabecerasDispensacion());

    esperarError($r, 500, 'ERROR_INTERNO');
    expect(mb_strtolower((string) $r->getContent()))->not->toContain('fallo inyectado')
        ->not->toContain('sqlstate')
        ->not->toContain('movimientos_kardex')
        ->and(DB::transactionLevel())->toBe(0, 'quedó una transacción abierta');

    // Lo confirmado, visto desde una conexión nueva.
    DB::purge();
    DB::reconnect();
    expect($contar())->toBe($antes)
        ->and($antes[0])->toBe(3);

    DB::unprepared('DROP TRIGGER rn03_conc_falla ON movimientos_kardex; DROP FUNCTION rn03_conc_falla()');

    olvidarAutenticacion();
    Sanctum::actingAs($usuario);
    $this->postJson('/api/v1/dispensaciones', $cuerpo, cabecerasDispensacion())->assertCreated();
    expect(rn03ConcStockDe($existencia))->toBe(2);
    rn03ConcAfirmarCadena($existencia, 'tras quitar el fallo');
})->group('concurrencia');
