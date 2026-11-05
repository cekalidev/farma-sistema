<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Support\Negocio;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * RN-06 bajo concurrencia: ajustes negativos y entradas simultáneos sobre la MISMA existencia.
 * Procesos separados (pcntl_fork), cada uno con su conexión a PostgreSQL y datos con commit real
 * (sin RefreshDatabase). Cada hijo hace la petición HTTP real (Form Request → servicio → BD).
 *
 * Se afirma: saldo final = inicial + Σ(operaciones aceptadas), nunca negativo; cada rechazo es
 * 409 STOCK_INSUFICIENTE; un movimiento por operación aceptada y la cadena de saldos del kardex
 * (en orden created_at, id, el orden de RN-06-AC8) sin huecos ni duplicados.
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-06 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Lanza un hijo por petición [uri, cuerpo]; todos esperan una barrera (flock) que el padre libera
 * a la vez. Devuelve, por hijo, "status|codigo_error".
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $peticiones
 * @return list<string>
 */
function rn06ConcEnParalelo(User $usuario, array $peticiones): array
{
    $dir = sys_get_temp_dir().'/rn06-concurrencia-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $barrera = "{$dir}/barrera";
    touch($barrera);

    $cerrojo = fopen($barrera, 'r');
    if ($cerrojo === false || ! flock($cerrojo, LOCK_EX)) {
        throw new RuntimeException('No se pudo tomar la barrera');
    }

    // Los hijos no deben heredar el socket de PostgreSQL del padre.
    DB::disconnect();

    $pids = [];
    foreach ($peticiones as $i => [$uri, $cuerpo]) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork falló');
        }

        if ($pid === 0) {
            $resultado = 'sin_resultado|';
            try {
                DB::purge();
                DB::reconnect();
                Sanctum::actingAs($usuario);

                $espera = fopen($barrera, 'r');
                flock($espera, LOCK_SH);

                $respuesta = test()->postJson($uri, $cuerpo);
                $resultado = $respuesta->getStatusCode().'|'.($respuesta->json('error.codigo') ?? '');
            } catch (Throwable $e) {
                $resultado = 'excepcion|'.$e::class.': '.$e->getMessage();
            }

            file_put_contents("{$dir}/resultado-{$i}", $resultado);
            DB::disconnect();
            exit(0);
        }

        $pids[] = $pid;
    }

    flock($cerrojo, LOCK_UN);
    fclose($cerrojo);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $estado);
    }

    DB::purge();
    DB::reconnect();

    $resultados = [];
    foreach (array_keys($peticiones) as $i) {
        $archivo = "{$dir}/resultado-{$i}";
        $resultados[] = is_file($archivo) ? (string) file_get_contents($archivo) : 'hijo_sin_archivo|';
        @unlink($archivo);
    }
    @unlink($barrera);
    @rmdir($dir);

    return $resultados;
}

/**
 * Existencia nueva con stock inicial registrado por una ENTRADA real (commit) y su lote.
 *
 * @return array{0: Existencia, 1: Bodega, 2: Lote}
 */
function rn06ConcExistencia(User $regente, int $inicial): array
{
    $bodega = Bodega::factory()->create();
    $lote = Lote::factory()->create([
        'producto_id' => Producto::factory()->create()->id,
        'fecha_vencimiento' => Negocio::hoy()->addDays(300)->toDateString(),
    ]);

    Sanctum::actingAs($regente);
    test()->postJson('/api/v1/inventario/entradas', [
        'bodega_id' => $bodega->id,
        'producto_id' => $lote->producto_id,
        'lote_id' => $lote->id,
        'cantidad' => $inicial,
    ])->assertCreated();

    $existencia = Existencia::query()->where(['bodega_id' => $bodega->id, 'lote_id' => $lote->id])->sole();

    return [$existencia, $bodega, $lote];
}

/**
 * Cadena de saldos de la existencia en orden created_at, id (y también en orden de id):
 * parte de 0, cada anterior = posterior previo, posterior = anterior + cantidad ≥ 0, y el
 * último posterior = SUM(cantidad) = existencias.cantidad.
 */
function rn06ConcAfirmarCadena(int $existenciaId, string $contexto): void
{
    $stock = (int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad');

    foreach (['created_at, id' => ['created_at', 'id'], 'id' => ['id']] as $nombreOrden => $columnas) {
        $consulta = DB::table('movimientos_kardex')->where('existencia_id', $existenciaId);
        foreach ($columnas as $columna) {
            $consulta->orderBy($columna);
        }
        $movimientos = $consulta->get()->all();

        $saldo = 0;
        $anteriores = [];
        foreach ($movimientos as $i => $m) {
            expect((int) $m->saldo_anterior)->toBe($saldo, "{$contexto} [orden {$nombreOrden}] movimiento #{$i}: hueco o salto en la cadena")
                ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
                ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
            $anteriores[] = (int) $m->saldo_anterior;
            $saldo = (int) $m->saldo_posterior;
        }

        expect($saldo)->toBe($stock, "{$contexto} [orden {$nombreOrden}]: último saldo ≠ existencia")
            ->and(array_sum(array_map(fn (object $m): int => (int) $m->cantidad, $movimientos)))->toBe($stock);
    }
}

it('RN-06-AC2 RN-06-AC7 concurrencia: N ajustes de −1 simultáneos por las últimas unidades → exactamente "stock" aceptados, el resto 409, saldo 0 y kardex encadenado', function () {
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

    foreach (range(1, 2) as $ronda) {
        [$existencia] = rn06ConcExistencia($regente, 5);

        $peticiones = array_fill(0, 8, ['/api/v1/inventario/ajustes', [
            'existencia_id' => $existencia->id,
            'cantidad' => -1,
            'motivo' => 'Merma detectada en conteo concurrente',
        ]]);

        $resultados = rn06ConcEnParalelo($regente, $peticiones);
        $conteo = array_count_values($resultados);

        expect($conteo['201|'] ?? 0)->toBe(5, "ronda {$ronda}: ".implode(', ', $resultados))
            ->and($conteo['409|STOCK_INSUFICIENTE'] ?? 0)->toBe(3, "ronda {$ronda}: ".implode(', ', $resultados))
            ->and((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(0)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'AJUSTE')->count())->toBe(5)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'ENTRADA')->count())->toBe(1);

        rn06ConcAfirmarCadena($existencia->id, "ronda {$ronda}");
    }
})->group('concurrencia');

it('RN-06-AC2 concurrencia: entradas y ajustes negativos mezclados sobre la misma existencia → saldo = inicial + Σ aceptadas, nunca negativo, cadena sin huecos ni duplicados', function (int $inicial, int $entradas, int $porEntrada, int $ajustes, int $porAjuste) {
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

    foreach (range(1, 2) as $ronda) {
        [$existencia, $bodega, $lote] = rn06ConcExistencia($regente, $inicial);

        $peticiones = [];
        // Intercaladas para que el orden de arranque no favorezca a un tipo.
        for ($i = 0; $i < max($entradas, $ajustes); $i++) {
            if ($i < $ajustes) {
                $peticiones[] = ['/api/v1/inventario/ajustes', [
                    'existencia_id' => $existencia->id,
                    'cantidad' => -$porAjuste,
                    'motivo' => 'Baja concurrente por producto dañado',
                ]];
            }
            if ($i < $entradas) {
                $peticiones[] = ['/api/v1/inventario/entradas', [
                    'bodega_id' => $bodega->id,
                    'producto_id' => $lote->producto_id,
                    'lote_id' => $lote->id,
                    'cantidad' => $porEntrada,
                ]];
            }
        }

        $resultados = rn06ConcEnParalelo($regente, $peticiones);
        $detalle = "ronda {$ronda}: ".implode(', ', $resultados);

        $ajustesOk = 0;
        foreach ($peticiones as $i => [$uri]) {
            if (str_ends_with($uri, '/entradas')) {
                expect($resultados[$i])->toBe('201|', "entrada rechazada — {$detalle}");
            } else {
                expect($resultados[$i])->toBeIn(['201|', '409|STOCK_INSUFICIENTE'], $detalle);
                $ajustesOk += $resultados[$i] === '201|' ? 1 : 0;
            }
        }

        $esperado = $inicial + $entradas * $porEntrada - $ajustesOk * $porAjuste;
        $stock = (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');

        expect($stock)->toBe($esperado, $detalle)
            ->and($stock)->toBeGreaterThanOrEqual(0)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'ENTRADA')->count())->toBe(1 + $entradas)
            ->and(DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->where('tipo', 'AJUSTE')->count())->toBe($ajustesOk)
            ->and(Existencia::query()->where(['bodega_id' => $bodega->id, 'lote_id' => $lote->id])->count())->toBe(1);

        rn06ConcAfirmarCadena($existencia->id, $detalle);
    }
})->with([
    '4 inicial, 4 entradas de +2, 8 ajustes de −3' => [4, 4, 2, 8, 3],
    '0 inicial efectivo (1), 3 entradas de +5, 6 ajustes de −4' => [1, 3, 5, 6, 4],
])->group('concurrencia');
