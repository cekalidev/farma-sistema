<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Models\Existencia;
use App\Services\Inventario\RegistradorKardex;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * RN-06 con commit real (sin RefreshDatabase):
 *  - AC2 tras `migrate:fresh --seed`: la semilla también escribe kardex y cuadra con existencias.
 *  - RegistradorKardex::registrar exige una transacción abierta (con RefreshDatabase siempre
 *    habría una, así que este caso solo se puede probar aquí).
 */

afterEach(function () {
    DB::purge();
    DB::reconnect();
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** Afirma, para TODAS las existencias, la cadena de saldos y SUM(cantidad) = existencias.cantidad. */
function rn06SemAfirmarKardexCuadrado(): void
{
    $existencias = DB::table('existencias')->orderBy('id')->get(['id', 'cantidad']);
    expect($existencias)->not->toBeEmpty('la semilla no creó existencias');

    foreach ($existencias as $e) {
        $movimientos = DB::table('movimientos_kardex')->where('existencia_id', $e->id)
            ->orderBy('created_at')->orderBy('id')->get()->all();

        if ((int) $e->cantidad > 0) {
            expect($movimientos)->not->toBeEmpty("existencia {$e->id} con stock {$e->cantidad} y sin kardex");
        }

        $saldo = 0;
        foreach ($movimientos as $i => $m) {
            expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$e->id}, movimiento #{$i}: la cadena no encadena")
                ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
                ->and(DB::table('users')->where('id', $m->usuario_id)->exists())->toBeTrue("movimiento {$m->id} sin usuario válido");
            $saldo = (int) $m->saldo_posterior;
        }

        expect($saldo)->toBe((int) $e->cantidad, "existencia {$e->id}: último saldo ≠ cantidad")
            ->and(array_sum(array_map(fn (object $m): int => (int) $m->cantidad, $movimientos)))->toBe((int) $e->cantidad, "existencia {$e->id}: SUM(cantidad) ≠ cantidad");
    }
}

describe('RN-06-AC2 S-38 semilla', function () {
    it('RN-06-AC2 S-38: tras migrate:fresh --seed cada existencia tiene kardex con SUM(cantidad) = existencias.cantidad, cadena de saldos y usuario válido; la carga inicial es ENTRADA', function () {
        $this->freezeTime();

        expect(Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]))->toBe(0, Artisan::output());

        rn06SemAfirmarKardexCuadrado();

        // La carga inicial de una semilla recién creada son compras: solo ENTRADA y con cantidad > 0.
        expect(DB::table('movimientos_kardex')->distinct()->pluck('tipo')->all())->toBe(['ENTRADA'])
            ->and(DB::table('movimientos_kardex')->where('cantidad', '<=', 0)->count())->toBe(0)
            ->and((int) DB::table('movimientos_kardex')->sum('cantidad'))->toBe((int) DB::table('existencias')->sum('cantidad'));
    });

    it('RN-06-AC2 S-38: volver a sembrar sobre la BD ya sembrada (db:seed) mantiene el kardex cuadrado y no duplica entradas', function () {
        $this->freezeTime();

        expect(Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]))->toBe(0, Artisan::output());
        $antes = DB::table('movimientos_kardex')->count();
        $stockAntes = DB::table('existencias')->orderBy('id')->pluck('cantidad', 'id')->all();

        expect(Artisan::call('db:seed', ['--force' => true]))->toBe(0, Artisan::output());

        expect(DB::table('existencias')->orderBy('id')->pluck('cantidad', 'id')->all())->toBe($stockAntes)
            ->and(DB::table('movimientos_kardex')->count())->toBe($antes);
        rn06SemAfirmarKardexCuadrado();
    });
});

describe('RN-06 RegistradorKardex fuera de transacción', function () {
    it('RN-06-AC7: registrar() sin una transacción abierta se rechaza y no cambia ni el stock ni el kardex', function (TipoMovimiento $tipo, int $cantidad, ?string $motivo) {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $usuario = usuarioConRol('regente_farmacia');
        $existencia = Existencia::factory()->create(['cantidad' => 10]);
        expect(DB::transactionLevel())->toBe(0);

        expect(fn () => app(RegistradorKardex::class)->registrar($tipo, $existencia->fresh(), $cantidad, $usuario->id, $motivo))
            ->toThrow(LogicException::class, 'RegistradorKardex::registrar exige una transacción abierta con la existencia bloqueada.');

        expect((int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(10)
            ->and(DB::table('movimientos_kardex')->count())->toBe(0);
    })->with([
        'ENTRADA +5' => [TipoMovimiento::Entrada, 5, null],
        'AJUSTE −3' => [TipoMovimiento::Ajuste, -3, 'Ajuste sin transacción de prueba'],
    ]);
});
