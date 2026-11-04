<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Fila cruda de `users` lista para INSERT directo (sin pasar por Eloquent ni por el enum).
 *
 * @return array<string, mixed>
 */
function filaUsuarioCruda(mixed $rol): array
{
    $fila = User::factory()->make(['rol' => Rol::from('admin')])->getAttributes();
    $fila['rol'] = $rol;
    $fila['created_at'] = now();
    $fila['updated_at'] = now();

    return $fila;
}

/**
 * Ejecuta SQL que debe fallar, dentro de un savepoint para no abortar la transacción de
 * RefreshDatabase, y devuelve el SQLSTATE.
 */
function sqlstateDe(Closure $operacion): ?string
{
    try {
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

dataset('base_roles_invalidos', [
    'rol inventado' => ['superadmin'],
    'cadena vacía' => [''],
    'mayúsculas' => ['ADMIN'],
    'capitalizado' => ['Admin'],
    'espacio inicial' => [' admin'],
    'espacio final' => ['regente_farmacia '],
    'prefijo de un rol' => ['regente'],
    'guion en vez de guion bajo' => ['auxiliar-farmacia'],
    'nombre del caso en vez del valor' => ['REGENTE_FARMACIA'],
]);

describe('BASE-AC5 users.rol solo admite valores del enum (CHECK en BD)', function () {
    it('BASE-AC5: INSERT directo con rol fuera del enum es rechazado por CHECK (23514) y no se crea el usuario', function (string $rolInvalido) {
        $antes = DB::table('users')->count();
        $fila = filaUsuarioCruda($rolInvalido);

        $sqlstate = sqlstateDe(fn () => DB::table('users')->insert($fila));

        expect($sqlstate)->toBe('23514')
            ->and(DB::table('users')->count())->toBe($antes)
            ->and(DB::table('users')->where('email', $fila['email'])->exists())->toBeFalse();
    })->with('base_roles_invalidos');

    it('BASE-AC5: UPDATE directo a un rol fuera del enum es rechazado por CHECK (23514) y el rol no cambia', function (string $rolInvalido) {
        $usuario = usuarioConRol('auditor');

        $sqlstate = sqlstateDe(fn () => DB::table('users')->where('id', $usuario->id)->update(['rol' => $rolInvalido]));

        expect($sqlstate)->toBe('23514')
            ->and(DB::table('users')->where('id', $usuario->id)->value('rol'))->toBe('auditor');
    })->with('base_roles_invalidos');

    it('BASE-AC5: rol NULL también es rechazado por la BD', function () {
        $antes = DB::table('users')->count();
        $fila = filaUsuarioCruda(null);

        $sqlstate = sqlstateDe(fn () => DB::table('users')->insert($fila));

        // NOT NULL (23502) o CHECK (23514): en cualquier caso la BD no admite un usuario sin rol.
        expect($sqlstate)->toBeIn(['23502', '23514'])
            ->and(DB::table('users')->count())->toBe($antes);
    });

    it('BASE-AC5: INSERT directo con cada valor válido del enum es aceptado (el CHECK no es más estricto que la matriz)', function (string $rol) {
        $fila = filaUsuarioCruda($rol);

        $sqlstate = sqlstateDe(fn () => DB::table('users')->insert($fila));

        expect($sqlstate)->toBeNull()
            ->and(DB::table('users')->where('email', $fila['email'])->value('rol'))->toBe($rol);

        $usuario = User::query()->where('email', $fila['email'])->firstOrFail();
        expect($usuario->rol)->toBe(Rol::from($rol));
    })->with([
        'auxiliar_farmacia',
        'regente_farmacia',
        'medico',
        'auditor',
        'admin',
    ]);
});

describe('BASE-AC5 integridad del modelo: correo único sin distinguir mayúsculas y stock protegido', function () {
    it('BASE-AC5: el índice único sobre lower(email) rechaza (23505) un duplicado que solo cambia mayúsculas, incluso con INSERT directo', function (string $variante) {
        $fila = filaUsuarioCruda('auditor');
        $fila['email'] = 'duplicado@farma.test';
        DB::table('users')->insert($fila);

        $otra = filaUsuarioCruda('medico');
        $otra['email'] = $variante;

        $sqlstate = sqlstateDe(fn () => DB::table('users')->insert($otra));

        expect($sqlstate)->toBe('23505')
            ->and(DB::table('users')->whereRaw('lower(email) = ?', ['duplicado@farma.test'])->count())->toBe(1);
    })->with([
        'todo mayúsculas' => ['DUPLICADO@FARMA.TEST'],
        'capitalizado' => ['Duplicado@farma.test'],
        'idéntico' => ['duplicado@farma.test'],
    ]);

    it('BASE-AC5: crear un usuario con correo en otra capitalización por Eloquent también falla y no crea otro', function () {
        usuarioConRol('auditor', ['email' => 'unico@farma.test']);

        // En un savepoint, para poder consultar después sin abortar la transacción del test.
        expect(fn () => DB::transaction(fn () => usuarioConRol('medico', ['email' => 'UNICO@Farma.Test'])))
            ->toThrow(QueryException::class);

        expect(DB::table('users')->count())->toBe(1)
            ->and(DB::table('users')->value('rol'))->toBe('auditor');
    });

    it('BASE-AC5: Existencia no admite asignación masiva de cantidad (el stock solo cambia vía servicio)', function () {
        $lote = Lote::factory()->create();
        $bodega = Bodega::factory()->create();

        $existencia = Existencia::query()->create([
            'bodega_id' => $bodega->id,
            'producto_id' => $lote->producto_id,
            'lote_id' => $lote->id,
            'cantidad' => 500,
        ]);

        expect($existencia->cantidad)->toBe(0)
            ->and(DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(0);

        $existencia->update(['cantidad' => 999]);
        expect(DB::table('existencias')->where('id', $existencia->id)->value('cantidad'))->toBe(0);
    });
});
