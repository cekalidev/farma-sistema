<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UsuariosSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * db:seed con --force (en production Artisan pide confirmación sin él).
 */
function sembrarForzado(): int
{
    return Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
}

/*
 * BASE-AC6: la semilla crea usuarios con una contraseña de desarrollo conocida; nunca debe
 * ejecutarse en producción salvo autorización explícita (FARMA_PERMITIR_SEMILLA=true).
 */

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
});

describe('BASE-AC6 la semilla no se ejecuta en producción', function () {
    it('BASE-AC6: en production el DatabaseSeeder aborta con un mensaje claro y no crea nada', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['farma.permitir_semilla_en_produccion' => false]);

        expect(fn () => sembrarForzado())
            ->toThrow(RuntimeException::class, 'FARMA_PERMITIR_SEMILLA');

        foreach (['users', 'bodegas', 'productos', 'lotes', 'existencias', 'pacientes'] as $tabla) {
            expect(DB::table($tabla)->count())->toBe(0, "la semilla escribió en {$tabla}");
        }
    });

    it('BASE-AC6: en production con FARMA_PERMITIR_SEMILLA=true la semilla sí se ejecuta', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['farma.permitir_semilla_en_produccion' => true]);

        expect(sembrarForzado())->toBe(0, Artisan::output());

        expect(DB::table('users')->count())->toBe(6);
    });

    it('BASE-AC6: en production el UsuariosSeeder llamado directamente (db:seed --class) también aborta y no crea usuarios', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['farma.permitir_semilla_en_produccion' => false]);

        expect(fn () => Artisan::call('db:seed', ['--class' => UsuariosSeeder::class, '--force' => true]))
            ->toThrow(RuntimeException::class, 'FARMA_PERMITIR_SEMILLA');

        expect(DB::table('users')->count())->toBe(0);
    });

    it('BASE-AC6: en production con FARMA_PERMITIR_SEMILLA=true el UsuariosSeeder directo sí crea los usuarios', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['farma.permitir_semilla_en_produccion' => true]);

        expect(Artisan::call('db:seed', ['--class' => UsuariosSeeder::class, '--force' => true]))->toBe(0, Artisan::output());

        expect(DB::table('users')->count())->toBe(count(UsuariosSeeder::USUARIOS));
    });
});
