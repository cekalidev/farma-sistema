<?php

declare(strict_types=1);

use Database\Seeders\UsuariosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * BASE-AC6 ejecuta migraciones y seeders con commit real: NO usa RefreshDatabase (está en
 * tests/Feature/SinTransaccion). Al terminar deja el esquema recién migrado y vacío para que
 * los tests con RefreshDatabase del mismo proceso no vean datos sembrados.
 */

afterEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * Conteos que deben ser idénticos entre una ejecución del seeder y la siguiente.
 *
 * @return array<string, int>
 */
function conteosSemilla(): array
{
    $tablas = ['users', 'bodegas', 'productos', 'lotes', 'existencias', 'stock_minimos', 'pacientes', 'prescripciones', 'prescripcion_items'];

    return array_combine($tablas, array_map(fn (string $t): int => DB::table($t)->count(), $tablas));
}

/**
 * Afirma el contenido mínimo que BASE-AC6 exige al seeder.
 */
function afirmarDatosSemillaBase(): void
{
    $hoy = Carbon::now('America/Bogota')->toDateString();
    $en30Dias = Carbon::now('America/Bogota')->addDays(30)->toDateString();

    // Tablas del modelo base.
    foreach (['bodegas', 'productos', 'lotes', 'existencias', 'stock_minimos', 'pacientes', 'prescripciones', 'prescripcion_items'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue("falta la tabla {$tabla}");
    }

    // 3 bodegas.
    expect(DB::table('bodegas')->count())->toBe(3);

    // 6 productos, exactamente 1 de control especial.
    expect(DB::table('productos')->count())->toBe(6)
        ->and(DB::table('productos')->where('control_especial', true)->count())->toBe(1);

    // Cada producto con 2–3 lotes (ninguno sin lotes).
    $lotesPorProducto = DB::table('productos')
        ->leftJoin('lotes', 'lotes.producto_id', '=', 'productos.id')
        ->groupBy('productos.id')
        ->selectRaw('productos.id, COUNT(lotes.id) AS total')
        ->pluck('total', 'productos.id');

    expect($lotesPorProducto)->toHaveCount(6);
    foreach ($lotesPorProducto as $productoId => $total) {
        expect((int) $total)->toBeGreaterThanOrEqual(2, "producto {$productoId} con {$total} lotes")
            ->toBeLessThanOrEqual(3, "producto {$productoId} con {$total} lotes");
    }

    // ≥1 lote vencido (S-01: fecha_vencimiento <= hoy, zona Bogotá).
    expect(DB::table('lotes')->whereDate('fecha_vencimiento', '<=', $hoy)->count())->toBeGreaterThanOrEqual(1);

    // ≥1 lote que vence en menos de 30 días y aún no está vencido (hoy < fv < hoy+30).
    expect(DB::table('lotes')
        ->whereDate('fecha_vencimiento', '>', $hoy)
        ->whereDate('fecha_vencimiento', '<', $en30Dias)
        ->count())->toBeGreaterThanOrEqual(1);

    // 3 pacientes, cada uno con al menos una prescripción vigente (S-05: ACTIVA y hoy <= fv).
    expect(DB::table('pacientes')->count())->toBe(3);

    $pacientesConVigente = DB::table('prescripciones')
        ->where('estado', 'ACTIVA')
        ->whereDate('fecha_vencimiento', '>=', $hoy)
        ->distinct()
        ->count('paciente_id');
    expect($pacientesConVigente)->toBe(3);

    // Al menos una prescripción vigente con un ítem de producto de control especial.
    $vigentesConControl = DB::table('prescripciones')
        ->join('prescripcion_items', 'prescripcion_items.prescripcion_id', '=', 'prescripciones.id')
        ->join('productos', 'productos.id', '=', 'prescripcion_items.producto_id')
        ->where('prescripciones.estado', 'ACTIVA')
        ->whereDate('prescripciones.fecha_vencimiento', '>=', $hoy)
        ->where('productos.control_especial', true)
        ->distinct()
        ->count('prescripciones.id');
    expect($vigentesConControl)->toBeGreaterThanOrEqual(1);

    // Exactamente 1 usuario por rol, salvo regente_farmacia con 2 (S-19).
    $usuariosPorRol = DB::table('users')
        ->groupBy('rol')
        ->selectRaw('rol, COUNT(*) AS total')
        ->pluck('total', 'rol')
        ->map(fn ($total): int => (int) $total)
        ->sortKeys()
        ->all();

    expect($usuariosPorRol)->toBe([
        'admin' => 1,
        'auditor' => 1,
        'auxiliar_farmacia' => 1,
        'medico' => 1,
        'regente_farmacia' => 2,
    ]);

    expect(DB::table('users')->where('rol', 'regente_farmacia')->orderBy('email')->pluck('email')->all())
        ->toBe(['regente2@farma.test', 'regente@farma.test']);
}

describe('BASE-AC6 migrate:fresh --seed repetible con datos sintéticos', function () {
    it('BASE-AC6: migrate:fresh --seed ejecutado dos veces termina sin error y deja los datos base de la spec', function () {
        $this->freezeTime();

        $conteos = [];
        foreach ([1, 2] as $ejecucion) {
            $codigo = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

            expect($codigo)->toBe(0, "migrate:fresh --seed (ejecución {$ejecucion}) falló:\n".Artisan::output());
            $conteos[$ejecucion] = conteosSemilla();
        }

        // La segunda ejecución deja exactamente lo mismo que la primera.
        expect($conteos[2])->toBe($conteos[1]);

        afirmarDatosSemillaBase();
    });

    it('BASE-AC6: las migraciones son reversibles (migrate:refresh --seed tras migrate:fresh --seed) y el seed vuelve a cumplir', function () {
        $this->freezeTime();

        expect(Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]))
            ->toBe(0, Artisan::output());
        $antes = conteosSemilla();

        expect(Artisan::call('migrate:refresh', ['--seed' => true, '--force' => true]))
            ->toBe(0, Artisan::output());

        expect(conteosSemilla())->toBe($antes);
        afirmarDatosSemillaBase();
    });
});

describe('BASE-AC1 login con usuarios sembrados', function () {
    it('BASE-AC1: tras migrate:fresh --seed, un usuario sembrado entra con la contraseña de desarrollo y recibe token y rol', function (string $correo, string $rol) {
        expect(Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]))->toBe(0, Artisan::output());

        $respuesta = $this->postJson('/api/v1/auth/login', [
            'email' => $correo,
            'password' => UsuariosSeeder::PASSWORD_DESARROLLO,
        ]);

        $respuesta->assertOk();
        $datos = cuerpoDatos($respuesta);
        $id = DB::table('users')->where('email', $correo)->value('id');

        expect($datos['token'] ?? null)->toBeString()->not->toBe('')
            ->and($datos['rol'] ?? null)->toBe($rol)
            ->and($datos['usuario']['id'] ?? null)->toBe($id)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(1);
    })->with([
        'regente' => ['regente@farma.test', 'regente_farmacia'],
        'auditor' => ['auditor@farma.test', 'auditor'],
    ]);
});
