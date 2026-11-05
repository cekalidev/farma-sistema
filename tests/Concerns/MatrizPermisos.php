<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Producto;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Support\Negocio;
use Illuminate\Support\Facades\DB;
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

            // --- RN-01: inventario (consultar: auxiliar, regente, auditor; entradas: regente, S-17)
            'GET /api/v1/inventario' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $bodega = Bodega::factory()->create();
                    Existencia::factory()->count(2)->create(['bodega_id' => $bodega->id]);
                    Existencia::factory()->create(); // otra bodega: el filtro la excluye

                    return ['uri' => '/api/v1/inventario?bodega_id='.$bodega->id];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => [Existencia::query()->count(), (int) Existencia::query()->sum('cantidad')],
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(2)
                        ->and($r->json('data.0'))->toHaveKeys(['lote.codigo', 'fecha_vencimiento', 'cantidad', 'vencido'])
                        ->and(Existencia::query()->count())->toBe($antes[0]);
                },
            ],
            'POST /api/v1/inventario/entradas' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/inventario/entradas',
                    'cuerpo' => [
                        'bodega_id' => Bodega::factory()->create()->id,
                        'producto_id' => Producto::factory()->create()->id,
                        'cantidad' => 25,
                        'lote' => [
                            'codigo' => 'L-MATRIZ-1',
                            'fecha_vencimiento' => Negocio::hoy()->addDays(200)->toDateString(),
                        ],
                    ],
                ],
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => [
                    Lote::query()->count(),
                    Existencia::query()->count(),
                    (int) Existencia::query()->sum('cantidad'),
                ],
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertCreated();
                    expect(cuerpoDatos($r)['cantidad'] ?? null)->toBe(25)
                        ->and(Lote::query()->count())->toBe($antes[0] + 1)
                        ->and(Existencia::query()->count())->toBe($antes[1] + 1)
                        ->and((int) Existencia::query()->sum('cantidad'))->toBe($antes[2] + 25);
                },
            ],

            // --- RN-06: kardex (consultar: auxiliar, regente, auditor; ajustes: regente) -----------
            'POST /api/v1/inventario/ajustes' => [
                'metodo' => 'POST',
                'preparar' => function (): array {
                    $existencia = self::existenciaConKardex(30);

                    return [
                        'uri' => '/api/v1/inventario/ajustes',
                        'cuerpo' => [
                            'existencia_id' => $existencia->id,
                            'cantidad' => -5,
                            'motivo' => 'Conteo físico de matriz con faltante',
                        ],
                    ];
                },
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => [
                    MovimientoKardex::query()->count(),
                    (int) Existencia::query()->sum('cantidad'),
                ],
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertCreated();
                    $datos = cuerpoDatos($r);
                    expect($datos['tipo'] ?? null)->toBe('AJUSTE')
                        ->and($datos['cantidad'] ?? null)->toBe(-5)
                        ->and($datos['usuario_id'] ?? null)->toBe($usuario->id)
                        ->and(MovimientoKardex::query()->count())->toBe($antes[0] + 1)
                        ->and((int) Existencia::query()->sum('cantidad'))->toBe($antes[1] - 5);
                },
            ],
            'GET /api/v1/kardex' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $existencia = self::existenciaConKardex(12);
                    self::existenciaConKardex(7); // otro producto: el filtro lo excluye

                    return ['uri' => '/api/v1/kardex?producto_id='.$existencia->producto_id];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): int => MovimientoKardex::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(1)
                        ->and($r->json('data.0.cantidad'))->toBe(12)
                        ->and(MovimientoKardex::query()->count())->toBe($antes);
                },
            ],
            'GET /api/v1/kardex/{id}' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    self::existenciaConKardex(9);

                    return ['uri' => '/api/v1/kardex/'.MovimientoKardex::query()->sole()->id];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): int => MovimientoKardex::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect(cuerpoDatos($r)['id'] ?? null)->toBe(MovimientoKardex::query()->sole()->id)
                        ->and(cuerpoDatos($r)['tipo'] ?? null)->toBe('ENTRADA');
                },
            ],

            // --- RN-xx: añadir aquí los endpoints de cada RN siguiente ------------------------
        ];
    }

    /**
     * Existencia en 0 que recibe `cantidad` por el camino real (RegistradorKardex, movimiento
     * ENTRADA), para que el kardex cuadre con el stock (RN-06-AC2).
     */
    private static function existenciaConKardex(int $cantidad): Existencia
    {
        $existencia = Existencia::factory()->create(['cantidad' => 0]);
        $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia]);

        DB::transaction(function () use ($existencia, $cantidad, $regente): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
        });

        return $existencia->refresh();
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
