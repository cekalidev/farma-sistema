<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\Bodega;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/*
 * RN-01-AC1 + RN-01-AC8 bajo concurrencia: varias entradas simultáneas sobre una existencia que
 * aún no existe (misma bodega + producto + lote) no deben crear dos existencias (ni dos lotes) ni
 * perder cantidad. Procesos separados (pcntl_fork) con conexiones propias y datos con commit real:
 * por eso vive en tests/Feature/SinTransaccion (sin RefreshDatabase).
 *
 * Cada hijo hace la petición HTTP real (Form Request → servicio → BD) por el kernel de la app.
 */

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('RN-01 concurrencia: instala la extensión pcntl para ejecutar este test.');
    }

    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Lanza un hijo por cuerpo; todos esperan una barrera (flock) que el padre libera a la vez.
 * Devuelve, por hijo, "status|codigo_error" (codigo vacío si no hubo error).
 *
 * @param  list<array<string, mixed>>  $cuerpos
 * @return list<string>
 */
function rn01EntradasEnParalelo(User $regente, array $cuerpos): array
{
    $dir = sys_get_temp_dir().'/rn01-concurrencia-'.bin2hex(random_bytes(6));
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
    foreach ($cuerpos as $i => $cuerpo) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork falló');
        }

        if ($pid === 0) {
            $resultado = 'sin_resultado|';
            try {
                DB::purge();
                DB::reconnect();
                Sanctum::actingAs($regente);

                // Nueva descripción de archivo: el LOCK_SH bloquea hasta que el padre suelte el LOCK_EX.
                $espera = fopen($barrera, 'r');
                flock($espera, LOCK_SH);

                $respuesta = test()->postJson('/api/v1/inventario/entradas', $cuerpo);
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

    // Todos los hijos lanzados: se suelta la barrera para que arranquen a la vez.
    flock($cerrojo, LOCK_UN);
    fclose($cerrojo);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $estado);
    }

    DB::purge();
    DB::reconnect();

    $resultados = [];
    foreach (array_keys($cuerpos) as $i) {
        $archivo = "{$dir}/resultado-{$i}";
        $resultados[] = is_file($archivo) ? (string) file_get_contents($archivo) : 'hijo_sin_archivo|';
        @unlink($archivo);
    }
    @unlink($barrera);
    @rmdir($dir);

    return $resultados;
}

it('RN-01-AC1 RN-01-AC8 concurrencia: N entradas simultáneas sobre una existencia nueva crean UNA existencia (y un lote) con la suma exacta', function (string $forma) {
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);
    $producto = Producto::factory()->create();
    $fecha = now('America/Bogota')->startOfDay()->addDays(200)->toDateString();
    $cantidades = [3, 5, 7, 11];

    // Varias rondas, cada una sobre una bodega nueva, para que una carrera tenga ocasión de aparecer.
    foreach (range(1, 3) as $ronda) {
        $bodega = Bodega::factory()->create();
        $codigo = "RN01-CONC-{$forma}-{$ronda}";
        $loteExistente = $forma === 'lote_id'
            ? Lote::factory()->create(['producto_id' => $producto->id, 'codigo' => $codigo, 'fecha_vencimiento' => $fecha])
            : null;
        $kardexAntes = Schema::hasTable('movimientos_kardex') ? DB::table('movimientos_kardex')->count() : 0;

        $cuerpos = array_map(fn (int $cantidad): array => [
            'bodega_id' => $bodega->id,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
        ] + ($loteExistente !== null
            ? ['lote_id' => $loteExistente->id]
            : ['lote' => ['codigo' => $codigo, 'fecha_vencimiento' => $fecha]]), $cantidades);

        $resultados = rn01EntradasEnParalelo($regente, $cuerpos);

        // Ninguna entrada se pierde ni se rechaza: todas eran válidas.
        expect($resultados)->toBe(array_fill(0, count($cantidades), '201|'), "ronda {$ronda}: ".implode(', ', $resultados));

        $lotes = DB::table('lotes')->where('producto_id', $producto->id)->where('codigo', $codigo)->get();
        expect($lotes)->toHaveCount(1, "ronda {$ronda}: lotes duplicados");

        $existencias = DB::table('existencias')
            ->where('bodega_id', $bodega->id)
            ->where('producto_id', $producto->id)
            ->where('lote_id', $lotes->first()->id)
            ->get();
        expect($existencias)->toHaveCount(1, "ronda {$ronda}: existencias duplicadas")
            ->and((int) $existencias->first()->cantidad)->toBe(array_sum($cantidades), "ronda {$ronda}: cantidad perdida");

        if (Schema::hasTable('movimientos_kardex')) {
            // Con RN-06: un movimiento ENTRADA por petición y saldos encadenados sin huecos.
            $movimientos = DB::table('movimientos_kardex')->where('id', '>', 0)->orderBy('id')->get()->slice($kardexAntes)->values();
            expect($movimientos)->toHaveCount(count($cantidades))
                ->and($movimientos->sum(fn (object $m): int => (int) $m->cantidad))->toBe(array_sum($cantidades))
                ->and((int) $movimientos->last()->saldo_posterior)->toBe(array_sum($cantidades));
        }
    }
})->with([
    'lote ya registrado (lote_id)' => ['lote_id'],
    'lote nuevo enviado en la entrada (lote.codigo + fecha)' => ['lote'],
])->group('concurrencia');
