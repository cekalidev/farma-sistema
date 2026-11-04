<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Matriz rol × endpoint de la spec (tabla "Roles y permisos", BASE-AC4).
 *
 * Cada RN que añada endpoints agrega aquí su entrada; BASE-AC3 (401 sin token / token revocado)
 * y BASE-AC4 (2xx permitido / 403 NO_AUTORIZADO) se ejecutan automáticamente sobre todas.
 *
 * Forma de cada entrada (clave = "MÉTODO /uri" legible):
 *  - metodo      string                       GET | POST | PUT | PATCH | DELETE
 *  - uri         string                       ruta concreta (sin parámetros) …
 *  - preparar    ?Closure(): array            … o datos a crear antes: devuelve ['uri' => …, 'cuerpo' => […]]
 *  - permitidos  list<string>                 valores de App\Enums\Rol con permiso según la spec
 *  - instantanea ?Closure(): mixed            estado de BD a comparar: en 403/401 debe quedar igual
 *  - verificar   ?Closure(TestResponse, mixed $antes, User $usuario): void
 *                                             efecto esperado cuando el rol está permitido
 */
final class MatrizPermisos
{
    public const ROLES = [
        'auxiliar_farmacia',
        'regente_farmacia',
        'medico',
        'auditor',
        'admin',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function endpoints(): array
    {
        return [
            // --- BASE: autenticación (cualquier rol autenticado) -------------------------------
            'GET /api/v1/auth/me' => [
                'metodo' => 'GET',
                'uri' => '/api/v1/auth/me',
                'permitidos' => self::ROLES,
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    $datos = cuerpoDatos($r);
                    expect($datos['id'] ?? null)->toBe($usuario->id)
                        ->and($datos['rol'] ?? null)->toBe($usuario->rol->value)
                        ->and($datos)->not->toHaveKey('password');
                },
            ],
            'POST /api/v1/auth/logout' => [
                'metodo' => 'POST',
                'uri' => '/api/v1/auth/logout',
                'permitidos' => self::ROLES,
            ],

            // --- BASE: catálogos (lectura según S-18; alta solo admin, "Gestión de catálogos") ---
            'GET /api/v1/bodegas' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    Bodega::factory()->count(2)->create();

                    return ['uri' => '/api/v1/bodegas'];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor', 'admin'],
                'instantanea' => fn (): int => Bodega::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(2);
                },
            ],
            'POST /api/v1/bodegas' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/bodegas',
                    'cuerpo' => Bodega::factory()->raw(),
                ],
                'permitidos' => ['admin'],
                'instantanea' => fn (): int => Bodega::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertSuccessful();
                    expect(Bodega::query()->count())->toBe($antes + 1);
                },
            ],
            'GET /api/v1/productos' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    Producto::factory()->count(2)->create();

                    return ['uri' => '/api/v1/productos'];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'],
                'instantanea' => fn (): int => Producto::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(2);
                },
            ],
            'POST /api/v1/productos' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/productos',
                    'cuerpo' => Producto::factory()->raw(),
                ],
                'permitidos' => ['admin'],
                'instantanea' => fn (): int => Producto::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertSuccessful();
                    expect(Producto::query()->count())->toBe($antes + 1);
                },
            ],

            'GET /api/v1/bodegas/{id}' => [
                'metodo' => 'GET',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/bodegas/'.Bodega::factory()->create()->id,
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor', 'admin'],
                'instantanea' => fn (): int => Bodega::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect(cuerpoDatos($r)['id'] ?? null)->toBe(Bodega::query()->sole()->id);
                },
            ],
            'GET /api/v1/productos/{id}' => [
                'metodo' => 'GET',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/productos/'.Producto::factory()->create()->id,
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'],
                'instantanea' => fn (): int => Producto::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect(cuerpoDatos($r)['id'] ?? null)->toBe(Producto::query()->sole()->id);
                },
            ],

            // --- RN-xx: añadir aquí los endpoints de cada RN siguiente ------------------------
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function endpoint(string $clave): array
    {
        $endpoints = self::endpoints();

        if (! array_key_exists($clave, $endpoints)) {
            throw new \InvalidArgumentException("Endpoint no definido en la matriz: {$clave}");
        }

        return $endpoints[$clave];
    }

    /**
     * Dataset "rol → endpoint" para BASE-AC4.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function casosRolEndpoint(): array
    {
        $casos = [];

        foreach (array_keys(self::endpoints()) as $clave) {
            foreach (self::ROLES as $rol) {
                $permitido = in_array($rol, self::endpoints()[$clave]['permitidos'], true) ? 'permitido' : '403';
                $casos["{$rol} → {$clave} ({$permitido})"] = [$rol, $clave];
            }
        }

        return $casos;
    }

    /**
     * Dataset de endpoints protegidos para BASE-AC3.
     *
     * @return array<string, array{0: string}>
     */
    public static function casosEndpoint(): array
    {
        $casos = [];

        foreach (array_keys(self::endpoints()) as $clave) {
            $casos[$clave] = [$clave];
        }

        return $casos;
    }

    public static function permitido(string $rol, string $clave): bool
    {
        return in_array($rol, self::endpoint($clave)['permitidos'], true);
    }

    /**
     * Ejecuta la preparación del endpoint y devuelve [uri, cuerpo].
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function preparar(string $clave): array
    {
        $def = self::endpoint($clave);
        $preparado = isset($def['preparar']) ? ($def['preparar'])() : [];

        return [
            $preparado['uri'] ?? $def['uri'],
            $preparado['cuerpo'] ?? [],
        ];
    }

    public static function instantanea(string $clave): mixed
    {
        $def = self::endpoint($clave);

        return isset($def['instantanea']) ? ($def['instantanea'])() : null;
    }
}
