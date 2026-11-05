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
use Tests\Concerns\Concurrencia;

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
 * Lanza un hijo por cuerpo; todos arrancan a la vez tras la barrera en dos fases de Tests\Concerns\Concurrencia.
 * Devuelve, por hijo, "status|codigo_error" (codigo vacío si no hubo error).
 *
 * @param  list<array<string, mixed>>  $cuerpos
 * @return list<string>
 */
function rn01EntradasEnParalelo(User $regente, array $cuerpos): array
{
    $cuerpos = array_values($cuerpos);

    return Concurrencia::enParalelo(
        count($cuerpos),
        function (int $i) use ($cuerpos): string {
            try {
                $respuesta = test()->postJson('/api/v1/inventario/entradas', $cuerpos[$i]);

                return $respuesta->getStatusCode().'|'.($respuesta->json('error.codigo') ?? '');
            } catch (Throwable $e) {
                return 'excepcion|'.$e::class.': '.$e->getMessage();
            }
        },
        function () use ($regente): void {
            Sanctum::actingAs($regente);
        },
    );
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
