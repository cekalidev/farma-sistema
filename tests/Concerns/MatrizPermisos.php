<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Enums\TrasladoEstado;
use App\Models\AuditoriaAcceso;
use App\Models\Bodega;
use App\Models\Discrepancia;
use App\Models\Dispensacion;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\MovimientoKardex;
use App\Models\Paciente;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\StockMinimo;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Auditoria\Bitacora;
use App\Services\Dispensacion\ClaveIdempotencia;
use App\Services\Dispensacion\DatosDispensacion;
use App\Services\Dispensacion\DispensacionService;
use App\Services\Dispensacion\ItemSolicitado;
use App\Services\Dispensacion\RespuestaIdempotente;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\DatosTraslado;
use App\Services\Traslados\DespachoTraslado;
use App\Services\Traslados\ItemRecibido;
use App\Services\Traslados\ItemTraslado;
use App\Services\Traslados\RecepcionTraslado;
use App\Services\Traslados\TrasladoService;
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
 *  - preparar    ?Closure(): array            … o datos a crear antes: devuelve ['uri' => …, 'cuerpo' => […],
 *                                             'cabeceras' => […]] (cabeceras opcionales, p. ej. Idempotency-Key)
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

            // --- RN-02: dispensación FEFO (dispensacion.crear: auxiliar y regente) ---------------
            'POST /api/v1/dispensaciones' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/dispensaciones',
                    'cuerpo' => self::cuerpoDispensacion(20, 3),
                    // RN-09-AC3: obligatoria al crear; una nueva por petición.
                    'cabeceras' => cabecerasDispensacion(),
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => [
                    Dispensacion::query()->count(),
                    MovimientoKardex::query()->count(),
                    (int) Existencia::query()->sum('cantidad'),
                ],
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertCreated();
                    $datos = cuerpoDatos($r);
                    expect($datos['estado'] ?? null)->toBe('CONFIRMADA')
                        ->and($datos['dispensado_por_id'] ?? null)->toBe($usuario->id)
                        ->and($r->json('data.detalles'))->toHaveCount(1)
                        ->and($r->json('data.detalles.0.cantidad'))->toBe(3)
                        ->and($r->json('data.detalles.0.movimiento.tipo'))->toBe('SALIDA_DISPENSACION')
                        ->and(Dispensacion::query()->count())->toBe($antes[0] + 1)
                        ->and(MovimientoKardex::query()->count())->toBe($antes[1] + 1)
                        ->and((int) Existencia::query()->sum('cantidad'))->toBe($antes[2] - 3);
                },
            ],
            'POST /api/v1/dispensaciones/previsualizar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/dispensaciones/previsualizar',
                    'cuerpo' => self::cuerpoDispensacion(20, 3),
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => [
                    Dispensacion::query()->count(),
                    MovimientoKardex::query()->count(),
                    (int) Existencia::query()->sum('cantidad'),
                ],
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data.detalles'))->toHaveCount(1)
                        ->and($r->json('data.detalles.0.cantidad'))->toBe(3)
                        ->and([
                            Dispensacion::query()->count(),
                            MovimientoKardex::query()->count(),
                            (int) Existencia::query()->sum('cantidad'),
                        ])->toBe($antes);
                },
            ],

            // --- RN-05: autorizar / rechazar control especial (dispensacion.autorizar: regente) ---
            // La pendiente la crea un auxiliar, así que el regente de la matriz es el "segundo".
            'POST /api/v1/dispensaciones/{id}/autorizar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/dispensaciones/'.self::dispensacionPendiente(20, 3)->id.'/autorizar',
                ],
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => self::estadoDispensaciones(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    $datos = cuerpoDatos($r);
                    expect($datos['estado'] ?? null)->toBe('CONFIRMADA')
                        ->and($datos['autorizado_por_id'] ?? null)->toBe($usuario->id)
                        ->and($r->json('data.detalles'))->toHaveCount(1)
                        ->and($r->json('data.detalles.0.movimiento.tipo'))->toBe('SALIDA_DISPENSACION')
                        ->and(MovimientoKardex::query()->count())->toBe($antes[1] + 1)
                        ->and((int) Existencia::query()->sum('cantidad'))->toBe($antes[2] - 3);
                },
            ],
            'POST /api/v1/dispensaciones/{id}/rechazar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/dispensaciones/'.self::dispensacionPendiente(20, 3)->id.'/rechazar',
                    'cuerpo' => ['motivo' => 'Prescripción ilegible en la matriz'],
                ],
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => self::estadoDispensaciones(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    $datos = cuerpoDatos($r);
                    expect($datos['estado'] ?? null)->toBe('RECHAZADA')
                        ->and($datos['rechazado_por_id'] ?? null)->toBe($usuario->id)
                        ->and(MovimientoKardex::query()->count())->toBe($antes[1])
                        ->and((int) Existencia::query()->sum('cantidad'))->toBe($antes[2]);
                },
            ],

            // --- RN-07: traslados (consultar: auxiliar, regente, auditor; operar: auxiliar, regente;
            // editar ítems, solicitar y anular: el creador = usuario de la matriz; aprobar y resolver: regente)
            'GET /api/v1/traslados' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    self::trasladoEn(TrasladoEstado::Borrador);

                    return ['uri' => '/api/v1/traslados'];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(1)
                        ->and($r->json('data.0.estado'))->toBe('BORRADOR')
                        ->and(self::estadoTraslados())->toBe($antes);
                },
            ],
            'POST /api/v1/traslados' => [
                'metodo' => 'POST',
                'preparar' => function (): array {
                    $existencia = self::existenciaConKardex(20);

                    return [
                        'uri' => '/api/v1/traslados',
                        'cuerpo' => [
                            'bodega_origen_id' => $existencia->bodega_id,
                            'bodega_destino_id' => Bodega::factory()->create()->id,
                            'items' => [['producto_id' => $existencia->producto_id, 'lote_id' => $existencia->lote_id, 'cantidad' => 5]],
                        ],
                    ];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertCreated();
                    $datos = cuerpoDatos($r);
                    expect($datos['estado'] ?? null)->toBe('BORRADOR')
                        ->and($datos['creado_por_id'] ?? null)->toBe($usuario->id)
                        ->and($r->json('data.items'))->toHaveCount(1)
                        ->and(Traslado::query()->count())->toBe($antes[0] + 1)
                        ->and(self::estadoTraslados()[1])->toBe($antes[1])
                        ->and(self::estadoTraslados()[2])->toBe($antes[2]);
                },
            ],
            'GET /api/v1/traslados/{id}' => [
                'metodo' => 'GET',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/traslados/'.self::trasladoEn(TrasladoEstado::Borrador)->id,
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect(cuerpoDatos($r)['id'] ?? null)->toBe(Traslado::query()->sole()->id)
                        ->and($r->json('data.items'))->toHaveCount(1)
                        ->and($r->json('data.discrepancias'))->toBe([]);
                },
            ],
            'PUT /api/v1/traslados/{id}/items' => [
                'metodo' => 'PUT',
                'preparar' => function (): array {
                    $traslado = self::trasladoEn(TrasladoEstado::Borrador, self::usuarioActual());
                    $item = $traslado->items()->sole();

                    return [
                        'uri' => "/api/v1/traslados/{$traslado->id}/items",
                        'cuerpo' => ['items' => [['producto_id' => $item->producto_id, 'lote_id' => $item->lote_id, 'cantidad' => 4]]],
                    ];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('BORRADOR')
                        ->and($r->json('data.items'))->toHaveCount(1)
                        ->and($r->json('data.items.0.cantidad'))->toBe(4);
                },
            ],
            'POST /api/v1/traslados/{id}/solicitar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/traslados/'.self::trasladoEn(TrasladoEstado::Borrador, self::usuarioActual())->id.'/solicitar',
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('SOLICITADO')
                        ->and($r->json('data.solicitado_por_id'))->toBe($usuario->id)
                        ->and($r->json('data.solicitado_en'))->toBeString();
                },
            ],
            'POST /api/v1/traslados/{id}/aprobar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/traslados/'.self::trasladoEn(TrasladoEstado::Solicitado)->id.'/aprobar',
                ],
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('APROBADO')
                        ->and($r->json('data.aprobado_por_id'))->toBe($usuario->id)
                        ->and(self::estadoTraslados()[1])->toBe($antes[1]);
                },
            ],
            'POST /api/v1/traslados/{id}/despachar' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/traslados/'.self::trasladoEn(TrasladoEstado::Aprobado)->id.'/despachar',
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('EN_TRANSITO')
                        ->and($r->json('data.despachado_por_id'))->toBe($usuario->id)
                        ->and(MovimientoKardex::query()->where('tipo', 'SALIDA_TRASLADO')->count())->toBe(1)
                        ->and(self::estadoTraslados()[1])->toBe($antes[1] + 1)
                        ->and(self::estadoTraslados()[2])->toBe($antes[2] - 5);
                },
            ],
            'POST /api/v1/traslados/{id}/recibir' => [
                'metodo' => 'POST',
                'preparar' => function (): array {
                    $traslado = self::trasladoEn(TrasladoEstado::EnTransito);
                    $item = $traslado->items()->sole();

                    return [
                        'uri' => "/api/v1/traslados/{$traslado->id}/recibir",
                        'cuerpo' => ['items' => [['traslado_item_id' => $item->id, 'cantidad_recibida' => $item->cantidad]]],
                    ];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('RECIBIDO')
                        ->and($r->json('data.recibido_por_id'))->toBe($usuario->id)
                        ->and($r->json('data.discrepancias'))->toBe([])
                        ->and(self::estadoTraslados()[1])->toBe($antes[1] + 1)
                        ->and(self::estadoTraslados()[2])->toBe($antes[2] + 5);
                },
            ],
            'POST /api/v1/traslados/{id}/anular' => [
                'metodo' => 'POST',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/traslados/'.self::trasladoEn(TrasladoEstado::Solicitado, self::usuarioActual())->id.'/anular',
                    'cuerpo' => ['motivo' => 'Pedido duplicado en la matriz'],
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia'],
                'instantanea' => fn (): array => self::estadoTraslados(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('ANULADO')
                        ->and($r->json('data.anulado_por_id'))->toBe($usuario->id)
                        ->and($r->json('data.motivo_anulacion'))->toBe('Pedido duplicado en la matriz')
                        ->and(self::estadoTraslados()[1])->toBe($antes[1]);
                },
            ],
            'POST /api/v1/discrepancias/{id}/resolver' => [
                'metodo' => 'POST',
                'preparar' => function (): array {
                    $traslado = self::trasladoEn(TrasladoEstado::RecibidoParcial);
                    $discrepancia = Discrepancia::query()->whereRelation('item', 'traslado_id', $traslado->id)->sole();

                    return [
                        'uri' => "/api/v1/discrepancias/{$discrepancia->id}/resolver",
                        'cuerpo' => ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => 'Unidades devueltas al origen en la matriz'],
                    ];
                },
                'permitidos' => ['regente_farmacia'],
                'instantanea' => fn (): array => [...self::estadoTraslados(), Discrepancia::query()->pluck('estado')->map(fn ($e): string => $e->value)->all()],
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('RESUELTA')
                        ->and($r->json('data.tipo_resolucion'))->toBe('REINTEGRO_ORIGEN')
                        ->and($r->json('data.resuelto_por_id'))->toBe($usuario->id)
                        ->and(MovimientoKardex::query()->where('tipo', 'AJUSTE')->count())->toBe(1)
                        ->and(self::estadoTraslados()[2])->toBe($antes[2] + 2);
                },
            ],

            // --- RN-10: pacientes (paciente.consultar: auxiliar, regente, médico; auditor enmascarado;
            // admin 403), detalle de dispensación (S-77) y bitácora (auditor, admin). Un 403 de paciente
            // solo añade una fila DENEGADO: la instantánea cuenta las PERMITIDO.
            'GET /api/v1/pacientes/{id}' => [
                'metodo' => 'GET',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/pacientes/'.Paciente::factory()->create(['numero_documento' => '1234565678'])->id,
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor'],
                'instantanea' => fn (): array => self::accesosPermitidos(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    $documento = $usuario->rol === Rol::Auditor ? '******5678' : '1234565678';
                    expect($r->json('data.numero_documento'))->toBe($documento)
                        ->and(self::accesosPermitidos())->toBe([...$antes, 'PACIENTE_CONSULTAR']);
                },
            ],
            'POST /api/v1/pacientes/buscar' => [
                'metodo' => 'POST',
                'preparar' => function (): array {
                    $paciente = Paciente::factory()->create(['numero_documento' => '7777123456']);

                    return [
                        'uri' => '/api/v1/pacientes/buscar',
                        'cuerpo' => ['tipo_documento' => $paciente->tipo_documento->value, 'numero_documento' => '7777123456'],
                    ];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor'],
                'instantanea' => fn (): array => self::accesosPermitidos(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    $documento = $usuario->rol === Rol::Auditor ? '******3456' : '7777123456';
                    expect($r->json('data'))->toHaveCount(1)
                        ->and($r->json('data.0.numero_documento'))->toBe($documento)
                        ->and(self::accesosPermitidos())->toBe([...$antes, 'PACIENTE_BUSCAR']);
                },
            ],
            'GET /api/v1/pacientes/{id}/prescripciones' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $item = PrescripcionItem::factory()->create(['cantidad' => 30]);

                    return ['uri' => '/api/v1/pacientes/'.$item->prescripcion->paciente_id.'/prescripciones'];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor'],
                'instantanea' => fn (): array => self::accesosPermitidos(),
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data'))->toHaveCount(1)
                        ->and($r->json('data.0.items.0.cantidad_prescrita'))->toBe(30)
                        ->and($r->json('data.0.items.0.cantidad_pendiente'))->toBe(30)
                        ->and($r->json('data.0.paciente.enmascarado'))->toBe($usuario->rol === Rol::Auditor)
                        ->and(self::accesosPermitidos())->toBe([...$antes, 'PACIENTE_PRESCRIPCIONES']);
                },
            ],
            'GET /api/v1/dispensaciones/{id}' => [
                'metodo' => 'GET',
                'preparar' => fn (): array => [
                    'uri' => '/api/v1/dispensaciones/'.self::dispensacionPendiente(20, 3)->id,
                ],
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => [...self::estadoDispensaciones(), self::accesosPermitidos()],
                'verificar' => function (TestResponse $r, mixed $antes, User $usuario): void {
                    $r->assertOk();
                    expect($r->json('data.estado'))->toBe('PENDIENTE_AUTORIZACION')
                        ->and($r->json('data.paciente.id'))->toBe($r->json('data.paciente_id'))
                        ->and($r->json('data.paciente.enmascarado'))->toBe($usuario->rol === Rol::Auditor)
                        ->and(self::accesosPermitidos())->toBe([...$antes[3], 'DISPENSACION_CONSULTAR']);
                },
            ],
            'GET /api/v1/auditoria' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $usuario = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);
                    DB::transaction(fn () => app(Bitacora::class)->operacion(
                        $usuario->id,
                        AccionAuditoria::TrasladoAnular,
                        EntidadAuditada::Traslado,
                        99,
                    ));

                    return ['uri' => '/api/v1/auditoria'];
                },
                'permitidos' => ['auditor', 'admin'],
                'instantanea' => fn (): int => AuditoriaAcceso::query()->count(),
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toHaveCount(1)
                        ->and($r->json('data.0.accion'))->toBe('TRASLADO_ANULAR')
                        ->and($r->json('meta.total'))->toBe(1)
                        ->and(AuditoriaAcceso::query()->count())->toBe($antes);
                },
            ],

            // --- RN-11: alertas (inventario.consultar: auxiliar, regente, auditor) -------------
            'GET /api/v1/alertas/vencimientos' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $bodega = Bodega::factory()->create();
                    $porVencer = Lote::factory()->venceEn(10)->create();
                    $vencido = Lote::factory()->vencido()->create();
                    Existencia::factory()->paraLote($porVencer)->create(['bodega_id' => $bodega->id, 'cantidad' => 5]);
                    Existencia::factory()->paraLote($vencido)->create(['bodega_id' => $bodega->id, 'cantidad' => 3]);
                    Existencia::factory()->create(['bodega_id' => $bodega->id]); // vigente: no alerta
                    Existencia::factory()->paraLote(Lote::factory()->venceEn(5)->create())->create(); // otra bodega

                    return ['uri' => '/api/v1/alertas/vencimientos?bodega_id='.$bodega->id];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => [Existencia::query()->count(), (int) Existencia::query()->sum('cantidad')],
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(2)
                        ->and($r->json('data.0.tipo'))->toBe('VENCIDO')
                        ->and($r->json('data.1.tipo'))->toBe('POR_VENCER')
                        ->and($r->json('data.1.dias_restantes'))->toBe(10)
                        ->and([Existencia::query()->count(), (int) Existencia::query()->sum('cantidad')])->toBe($antes);
                },
            ],
            'GET /api/v1/alertas/stock-minimo' => [
                'metodo' => 'GET',
                'preparar' => function (): array {
                    $bodega = Bodega::factory()->create();
                    $lote = Lote::factory()->create();
                    Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 19]);
                    StockMinimo::factory()->create(['bodega_id' => $bodega->id, 'producto_id' => $lote->producto_id, 'cantidad_minima' => 20]);
                    StockMinimo::factory()->create(['cantidad_minima' => 5]); // otra bodega

                    return ['uri' => '/api/v1/alertas/stock-minimo?bodega_id='.$bodega->id];
                },
                'permitidos' => ['auxiliar_farmacia', 'regente_farmacia', 'auditor'],
                'instantanea' => fn (): array => [StockMinimo::query()->count(), (int) Existencia::query()->sum('cantidad')],
                'verificar' => function (TestResponse $r, mixed $antes): void {
                    $r->assertOk();
                    expect($r->json('data'))->toBeArray()->toHaveCount(1)
                        ->and($r->json('data.0.minimo'))->toBe(20)
                        ->and($r->json('data.0.actual'))->toBe(19)
                        ->and($r->json('data.0.faltante'))->toBe(1)
                        ->and([StockMinimo::query()->count(), (int) Existencia::query()->sum('cantidad')])->toBe($antes);
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

    /**
     * Cuerpo de dispensación válido: una existencia vigente con `stock` unidades (con su ENTRADA en
     * kardex) y una prescripción vigente con un ítem de ese producto.
     *
     * @return array<string, mixed>
     */
    private static function cuerpoDispensacion(int $stock, int $cantidad): array
    {
        $existencia = self::existenciaConKardex($stock);
        $item = PrescripcionItem::factory()->create(['producto_id' => $existencia->producto_id, 'cantidad' => 30]);

        return [
            'paciente_id' => $item->prescripcion->paciente_id,
            'prescripcion_id' => $item->prescripcion_id,
            'bodega_id' => $existencia->bodega_id,
            'items' => [['prescripcion_item_id' => $item->id, 'cantidad' => $cantidad]],
        ];
    }

    /**
     * RN-05: dispensación PENDIENTE_AUTORIZACION de un producto de control especial con `stock`
     * unidades, creada por un auxiliar por el camino real (DispensacionService) sin mover stock.
     */
    private static function dispensacionPendiente(int $stock, int $cantidad): Dispensacion
    {
        $existencia = self::existenciaConKardex($stock);
        Producto::query()->whereKey($existencia->producto_id)->update(['control_especial' => true]);
        $item = PrescripcionItem::factory()->create(['producto_id' => $existencia->producto_id, 'cantidad' => 30]);
        $auxiliar = User::factory()->create(['rol' => Rol::AuxiliarFarmacia]);

        app(DispensacionService::class)->crear(
            new DatosDispensacion(
                usuarioId: $auxiliar->id,
                pacienteId: $item->prescripcion->paciente_id,
                prescripcionId: $item->prescripcion_id,
                bodegaId: $existencia->bodega_id,
                items: [new ItemSolicitado($item->id, $cantidad)],
            ),
            new ClaveIdempotencia('matriz-rn05', str_repeat('0', 64)),
            fn (Dispensacion $d): RespuestaIdempotente => new RespuestaIdempotente(201, '{}'),
        );

        return Dispensacion::query()->where('dispensado_por_id', $auxiliar->id)->sole();
    }

    /**
     * Acciones PERMITIDO de la bitácora en orden de inserción: un 403 (DENEGADO) no la cambia.
     *
     * @return list<string>
     */
    private static function accesosPermitidos(): array
    {
        return AuditoriaAcceso::query()->where('resultado', 'PERMITIDO')->orderBy('id')->pluck('accion')
            ->map(fn ($a): string => $a->value)->values()->all();
    }

    /** @return array{0: list<string>, 1: int, 2: int} estados, movimientos de kardex y stock total */
    private static function estadoDispensaciones(): array
    {
        return [
            Dispensacion::query()->orderBy('id')->pluck('estado')->map(fn ($e): string => $e->value)->all(),
            MovimientoKardex::query()->count(),
            (int) Existencia::query()->sum('cantidad'),
        ];
    }

    /**
     * Usuario autenticado por la matriz (actuarComo se ejecuta antes de preparar()); sin sesión
     * (BASE-AC3, 401), un auxiliar cualquiera.
     */
    private static function usuarioActual(): int
    {
        $id = auth()->id();

        return is_int($id) ? $id : User::factory()->create(['rol' => Rol::AuxiliarFarmacia])->id;
    }

    /**
     * RN-07: traslado de 5 u. de un lote vigente (origen con 20 u. y su ENTRADA) llevado por el
     * camino real (servicios) hasta `$hasta`. Lo crea y solicita `$creadorId` (por defecto un
     * auxiliar nuevo); aprueba otro regente. RECIBIDO_PARCIAL = se reciben 3 (discrepancia de 2).
     */
    private static function trasladoEn(TrasladoEstado $hasta, ?int $creadorId = null): Traslado
    {
        $existencia = self::existenciaConKardex(20);
        $creadorId ??= User::factory()->create(['rol' => Rol::AuxiliarFarmacia])->id;
        $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia])->id;
        $servicio = app(TrasladoService::class);

        $traslado = $servicio->crear(new DatosTraslado(
            usuarioId: $creadorId,
            bodegaOrigenId: $existencia->bodega_id,
            bodegaDestinoId: Bodega::factory()->create()->id,
            observaciones: null,
            items: [new ItemTraslado($existencia->producto_id, $existencia->lote_id, 5)],
        ));

        $pasos = [
            TrasladoEstado::Solicitado->value => fn (Traslado $t): Traslado => $servicio->solicitar($t, $creadorId),
            TrasladoEstado::Aprobado->value => fn (Traslado $t): Traslado => $servicio->aprobar($t, $regente),
            TrasladoEstado::EnTransito->value => fn (Traslado $t): Traslado => app(DespachoTraslado::class)->despachar($t, $regente),
            TrasladoEstado::RecibidoParcial->value => fn (Traslado $t): Traslado => app(RecepcionTraslado::class)->recibir(
                $t,
                $regente,
                [new ItemRecibido($t->items()->sole()->id, 3)],
            ),
        ];

        foreach ($pasos as $estado => $paso) {
            if ($traslado->estado === $hasta) {
                break;
            }
            $traslado = $paso($traslado);
            if ($estado === $hasta->value) {
                break;
            }
        }

        return $traslado->refresh();
    }

    /** @return array{0: int, 1: int, 2: int, 3: list<string>} traslados, movimientos, stock total y estados */
    private static function estadoTraslados(): array
    {
        return [
            Traslado::query()->count(),
            MovimientoKardex::query()->count(),
            (int) Existencia::query()->sum('cantidad'),
            Traslado::query()->orderBy('id')->pluck('estado')->map(fn ($e): string => $e->value)->all(),
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
     * Ejecuta la preparación del endpoint y devuelve [uri, cuerpo, cabeceras].
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>}
     */
    public static function preparar(string $clave): array
    {
        $def = self::endpoint($clave);
        $preparado = isset($def['preparar']) ? ($def['preparar'])() : [];

        return [
            $preparado['uri'] ?? $def['uri'],
            $preparado['cuerpo'] ?? [],
            $preparado['cabeceras'] ?? [],
        ];
    }

    public static function instantanea(string $clave): mixed
    {
        $def = self::endpoint($clave);

        return isset($def['instantanea']) ? ($def['instantanea'])() : null;
    }
}
