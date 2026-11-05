<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\NoAutorizado;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\DatosTraslado;
use App\Services\Traslados\ItemRecibido;
use App\Services\Traslados\ItemTraslado;
use App\Services\Traslados\RecepcionTraslado;
use App\Services\Traslados\TrasladoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
 * RN-07 — Máquina de estados del traslado (AC1–AC10, AC12, AC13) y criterios aplazados que se
 * cierran aquí: RN-01-AC6 / RN-01-AC7 (lote vencido en traslados) y RN-06-AC1 para
 * SALIDA_TRASLADO / ENTRADA_TRASLADO (documento `traslado` + id, S-35).
 * AC11 (despachos simultáneos, procesos separados) vive en
 * tests/Feature/SinTransaccion/RN07DespachoConcurrenteTest.php.
 *
 * Supuestos: S-01 (vence hoy = vencido), S-08 (solo el creador solicita), S-09 (no se anula lo
 * despachado), S-12 (lote explícito; el destino recibe el mismo lote; se acepta recibir un lote
 * que venció en tránsito), S-16 (quien recibe puede ser quien despachó), S-35, S-48.
 *
 * Todos los estados se preparan por el camino real (HTTP), nunca por SQL. El stock inicial se crea
 * por RegistradorKardex (ENTRADA) para que la cadena del kardex cuadre desde 0.
 *
 * Contrato HTTP supuesto (ver informe): POST /traslados {bodega_origen_id, bodega_destino_id,
 * items:[{producto_id, lote_id, cantidad}]}; PUT /traslados/{id}/items {items:[…]};
 * POST /traslados/{id}/{solicitar|aprobar|despachar|anular {motivo}|recibir
 * {items:[{traslado_item_id, cantidad_recibida}]}}; POST /discrepancias/{id}/resolver {tipo, motivo}.
 */

const RN07_URI = '/api/v1/traslados';
const RN07_URI_DISCREPANCIAS = '/api/v1/discrepancias';
const RN07_HOY = '2026-11-04';
const RN07_MOTIVO = 'Traslado anulado por cambio de plan RN-07';
const RN07_MOTIVO_RESOLUCION = 'Conteo de recepción confirmado RN-07';

/** Estado → acciones permitidas según la tabla de RN-07 (S-08, S-09). */
const RN07_PERMITIDAS = [
    'BORRADOR' => ['solicitar', 'anular', 'editar'],
    'SOLICITADO' => ['aprobar', 'anular'],
    'APROBADO' => ['despachar', 'anular'],
    'EN_TRANSITO' => ['recibir'],
    'RECIBIDO' => [],
    'RECIBIDO_PARCIAL' => [],
    'ANULADO' => [],
];

const RN07_ACCIONES = ['solicitar', 'aprobar', 'despachar', 'recibir', 'anular', 'editar'];

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn07)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en RN07_HOY + `dias`, a la hora dada (Bogotá). */
function rn07Congelar(int $dias = 0, string $hora = '10:00:00'): void
{
    test()->travelTo(Carbon::parse(rn07Dia($dias).' '.$hora, 'America/Bogota'));
}

/** Fecha Y-m-d a N días de RN07_HOY. */
function rn07Dia(int $dias): string
{
    return Carbon::parse(RN07_HOY, 'America/Bogota')->addDays($dias)->toDateString();
}

function rn07Carga(): User
{
    return User::query()->where('email', 'rn07-carga@farma.test')->first()
        ?? usuarioConRol('regente_farmacia', ['email' => 'rn07-carga@farma.test']);
}

/** Existencia (bodega, lote) con `cantidad` cargada por una ENTRADA real (cadena desde 0). */
function rn07Stock(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $carga = rn07Carga();
        DB::transaction(function () use ($existencia, $cantidad, $carga): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $carga->id);
        });
    }

    return $existencia->refresh();
}

/** Deja la existencia en `nuevo` con un AJUSTE real (para quitar stock tras aprobar). */
function rn07AjustarA(Existencia $existencia, int $nuevo): void
{
    $carga = rn07Carga();
    DB::transaction(function () use ($existencia, $nuevo, $carga): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        $delta = $nuevo - $bloqueada->cantidad;
        if ($delta !== 0) {
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Ajuste, $bloqueada, $delta, $carga->id, 'Conteo físico previo al despacho RN-07');
        }
    });
}

/**
 * Escenario: bodegas origen y destino, un producto y un lote por ítem, stock en origen y los
 * actores de cada paso (creador auxiliar, aprobador regente, despachador auxiliar, receptor auxiliar).
 *
 * @param  list<int>  $cantidades  cantidad de cada ítem
 * @param  list<int>|null  $stocks  stock en origen por ítem (por defecto cantidad + 15)
 * @param  list<int|null>  $stocksDestino  existencia previa en destino por ítem (null = no existe)
 * @param  list<int>  $diasVence  vencimiento de cada lote en días desde RN07_HOY (por defecto 200)
 * @return array<string, mixed>
 */
function rn07Escenario(array $cantidades = [10], ?array $stocks = null, array $stocksDestino = [], array $diasVence = []): array
{
    $origen = Bodega::factory()->create();
    $destino = Bodega::factory()->create();

    $lineas = [];
    foreach ($cantidades as $i => $cantidad) {
        $producto = Producto::factory()->create();
        $lote = Lote::factory()->create([
            'producto_id' => $producto->id,
            'fecha_vencimiento' => rn07Dia($diasVence[$i] ?? 200),
        ]);
        $existencia = rn07Stock($origen, $lote, $stocks[$i] ?? $cantidad + 15);
        $enDestino = isset($stocksDestino[$i]) ? rn07Stock($destino, $lote, $stocksDestino[$i]) : null;

        $lineas[] = ['producto' => $producto, 'lote' => $lote, 'cantidad' => $cantidad, 'existencia' => $existencia, 'destino' => $enDestino];
    }

    return [
        'origen' => $origen,
        'destino' => $destino,
        'lineas' => $lineas,
        'creador' => usuarioConRol('auxiliar_farmacia'),
        'regente' => usuarioConRol('regente_farmacia'),
        'despachador' => usuarioConRol('auxiliar_farmacia'),
        'receptor' => usuarioConRol('auxiliar_farmacia'),
    ];
}

/**
 * @param  array<string, mixed>  $e
 * @return list<array{producto_id: int, lote_id: int, cantidad: int}>
 */
function rn07ItemsCuerpo(array $e, ?Closure $cantidad = null): array
{
    return array_map(fn (array $l): array => [
        'producto_id' => $l['producto']->id,
        'lote_id' => $l['lote']->id,
        'cantidad' => $cantidad !== null ? $cantidad($l['cantidad']) : $l['cantidad'],
    ], $e['lineas']);
}

/**
 * @param  array<string, mixed>  $e
 * @return array<string, mixed>
 */
function rn07CuerpoCrear(array $e): array
{
    return [
        'bodega_origen_id' => $e['origen']->id,
        'bodega_destino_id' => $e['destino']->id,
        'items' => rn07ItemsCuerpo($e),
    ];
}

/** @param array<string, mixed> $cuerpo */
function rn07Crear(User $actor, array $cuerpo): TestResponse
{
    Sanctum::actingAs($actor);

    return test()->postJson(RN07_URI, $cuerpo);
}

/** Crea el borrador (debe responder 201) y devuelve su id. @param array<string, mixed> $e */
function rn07CrearOk(array $e): int
{
    $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));
    expect($r->getStatusCode())->toBe(201, 'crear: '.$r->getContent());
    $id = cuerpoDatos($r)['id'] ?? null;
    expect($id)->toBeInt();

    return $id;
}

/** @param array<string, mixed> $cuerpo */
function rn07Accion(User $actor, int $id, string $accion, array $cuerpo = []): TestResponse
{
    Sanctum::actingAs($actor);

    return $accion === 'editar'
        ? test()->putJson(RN07_URI."/{$id}/items", $cuerpo)
        : test()->postJson(RN07_URI."/{$id}/{$accion}", $cuerpo);
}

function rn07Items(int $id): Collection
{
    return DB::table('traslado_items')->where('traslado_id', $id)->orderBy('id')->get();
}

/**
 * Cuerpo de recepción con TODOS los ítems; `recibidas[i]` (por posición) o, si falta, lo despachado.
 *
 * @param  array<int, int|null>  $recibidas
 * @return array{items: list<array{traslado_item_id: int, cantidad_recibida: int}>}
 */
function rn07CuerpoRecibir(int $id, array $recibidas = []): array
{
    return ['items' => rn07Items($id)->values()->map(fn (object $it, int $i): array => [
        'traslado_item_id' => (int) $it->id,
        'cantidad_recibida' => $recibidas[$i] ?? (int) $it->cantidad,
    ])->all()];
}

/** Actor autorizado para cada acción según la tabla de RN-07. @param array<string, mixed> $e */
function rn07Actor(array $e, string $accion): User
{
    return match ($accion) {
        'solicitar', 'anular', 'editar' => $e['creador'],
        'aprobar' => $e['regente'],
        'despachar' => $e['despachador'],
        'recibir', 'recibir_parcial' => $e['receptor'],
    };
}

/**
 * Cuerpo válido para cada acción.
 *
 * @param  array<string, mixed>  $e
 * @return array<string, mixed>
 */
function rn07CuerpoAccion(array $e, int $id, string $accion): array
{
    return match ($accion) {
        'recibir' => rn07CuerpoRecibir($id),
        'recibir_parcial' => rn07CuerpoRecibir($id, [((int) rn07Items($id)->first()->cantidad) - 1]),
        'anular' => ['motivo' => RN07_MOTIVO],
        'editar' => ['items' => rn07ItemsCuerpo($e, fn (int $c): int => max(1, $c - 1))],
        default => [],
    };
}

/** Ejecuta la acción con su actor y cuerpo válidos (o los dados). @param array<string, mixed> $e */
function rn07Hacer(array $e, int $id, string $accion, ?User $actor = null, ?array $cuerpo = null): TestResponse
{
    $ruta = $accion === 'recibir_parcial' ? 'recibir' : $accion;

    return rn07Accion($actor ?? rn07Actor($e, $accion), $id, $ruta, $cuerpo ?? rn07CuerpoAccion($e, $id, $accion));
}

/** Pasos reales (HTTP) para llegar a cada estado. */
function rn07Pasos(string $estado): array
{
    return match ($estado) {
        'BORRADOR' => [],
        'SOLICITADO' => ['solicitar'],
        'APROBADO' => ['solicitar', 'aprobar'],
        'EN_TRANSITO' => ['solicitar', 'aprobar', 'despachar'],
        'RECIBIDO' => ['solicitar', 'aprobar', 'despachar', 'recibir'],
        'RECIBIDO_PARCIAL' => ['solicitar', 'aprobar', 'despachar', 'recibir_parcial'],
        'ANULADO' => ['anular'],
    };
}

/** Crea el traslado y lo lleva a `estado` por el camino real. @param array<string, mixed> $e */
function rn07Llevar(array $e, string $estado): int
{
    $id = rn07CrearOk($e);
    foreach (rn07Pasos($estado) as $paso) {
        $r = rn07Hacer($e, $id, $paso);
        expect($r->getStatusCode())->toBe(200, "preparar {$estado}, paso {$paso}: ".$r->getContent());
    }
    expect(rn07Estado($id))->toBe($estado);

    return $id;
}

function rn07Fila(int $id): object
{
    $fila = DB::table('traslados')->where('id', $id)->first();
    expect($fila)->not->toBeNull();

    return $fila;
}

function rn07Estado(int $id): string
{
    return (string) DB::table('traslados')->where('id', $id)->value('estado');
}

/** Cantidad de la existencia (bodega, lote); null si la existencia no existe. */
function rn07StockEn(Bodega $bodega, Lote $lote): ?int
{
    $valor = DB::table('existencias')->where('bodega_id', $bodega->id)->where('lote_id', $lote->id)->value('cantidad');

    return $valor === null ? null : (int) $valor;
}

/** Movimientos de kardex que referencian el traslado (S-35). */
function rn07Movs(int $id): Collection
{
    return DB::table('movimientos_kardex')->where('documento_tipo', 'traslado')->where('documento_id', $id)->orderBy('id')->get();
}

/** Discrepancias de los ítems del traslado (ligadas al ítem). */
function rn07Discrepancias(int $id): Collection
{
    return DB::table('discrepancias')->whereIn('traslado_item_id', rn07Items($id)->pluck('id'))->orderBy('id')->get();
}

/** @return array<string, list<array<string, mixed>>> */
function rn07Instantanea(): array
{
    $filas = fn (string $tabla): array => DB::table($tabla)->orderBy('id')->get()
        ->map(fn (object $f): array => (array) $f)->all();

    return [
        'existencias' => $filas('existencias'),
        'kardex' => $filas('movimientos_kardex'),
        'traslados' => $filas('traslados'),
        'traslado_items' => $filas('traslado_items'),
        'discrepancias' => $filas('discrepancias'),
    ];
}

/** Cadena de saldos de la existencia desde 0; el último saldo = existencias.cantidad (RN-06-AC2). */
function rn07AfirmarCadena(int $existenciaId): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existenciaId)->orderBy('created_at')->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existenciaId}, movimiento #{$i}: hueco en la cadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }

    expect($saldo)->toBe((int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad'));
}

function rn07AfirmarValidacion(TestResponse $r): void
{
    esperarError($r, 422, 'VALIDACION');
    expect($r->json('error.detalles.campos'))->toBeArray()->not->toBeEmpty();
}

/** Ids de ítems de un traslado (en orden). @return list<int> */
function rn07IdsItems(int $id): array
{
    return rn07Items($id)->pluck('id')->map(fn ($v): int => (int) $v)->all();
}

/** Pares (estado, acción) que la tabla NO permite: 7 estados × 6 acciones − 8 válidos = 34. */
dataset('rn07_pares_invalidos', function () {
    foreach (RN07_PERMITIDAS as $estado => $permitidas) {
        foreach (RN07_ACCIONES as $accion) {
            if (! in_array($accion, $permitidas, true)) {
                yield "{$estado} × {$accion}" => [$estado, $accion];
            }
        }
    }
});

beforeEach(function () {
    rn07Congelar();
});

/*
|--------------------------------------------------------------------------
| AC1 — crear
|--------------------------------------------------------------------------
*/

describe('RN-07-AC1 crear traslado', function () {
    it('RN-07-AC1: POST /traslados válido → 201 BORRADOR con sus ítems, creado_por = actor y sin movimientos de stock ni kardex', function (string $rol, array $cantidades) {
        $e = rn07Escenario($cantidades, stocksDestino: [3]);
        $actor = usuarioConRol($rol);
        $antes = rn07Instantanea();

        $r = rn07Crear($actor, rn07CuerpoCrear($e));

        $r->assertCreated();
        $datos = cuerpoDatos($r);
        expect($datos['estado'] ?? null)->toBe('BORRADOR')
            ->and($datos['id'] ?? null)->toBeInt()
            ->and($datos['bodega_origen_id'] ?? null)->toBe($e['origen']->id)
            ->and($datos['bodega_destino_id'] ?? null)->toBe($e['destino']->id)
            ->and($datos['items'] ?? null)->toBeArray()->toHaveCount(count($cantidades));

        $fila = rn07Fila($datos['id']);
        expect($fila->estado)->toBe('BORRADOR')
            ->and((int) $fila->creado_por_id)->toBe($actor->id)
            ->and((int) $fila->bodega_origen_id)->toBe($e['origen']->id)
            ->and((int) $fila->bodega_destino_id)->toBe($e['destino']->id)
            ->and($fila->solicitado_por_id)->toBeNull()
            ->and($fila->aprobado_por_id)->toBeNull()
            ->and($fila->despachado_por_id)->toBeNull()
            ->and($fila->recibido_por_id)->toBeNull()
            ->and($fila->anulado_por_id)->toBeNull();

        $items = rn07Items($datos['id']);
        expect($items)->toHaveCount(count($cantidades));
        foreach ($e['lineas'] as $i => $l) {
            expect((int) $items[$i]->producto_id)->toBe($l['producto']->id)
                ->and((int) $items[$i]->lote_id)->toBe($l['lote']->id)
                ->and((int) $items[$i]->cantidad)->toBe($l['cantidad'])
                ->and($items[$i]->cantidad_recibida)->toBeNull();
        }

        $despues = rn07Instantanea();
        expect($despues['existencias'])->toEqual($antes['existencias'])
            ->and($despues['kardex'])->toEqual($antes['kardex'])
            ->and($despues['discrepancias'])->toBe([])
            ->and(rn07Movs($datos['id']))->toHaveCount(0);
    })->with([
        'auxiliar' => 'auxiliar_farmacia',
        'regente' => 'regente_farmacia',
    ])->with([
        'un ítem' => [[10]],
        'tres ítems' => [[10, 1, 7]],
    ]);
});

/*
|--------------------------------------------------------------------------
| AC2 — camino feliz
|--------------------------------------------------------------------------
*/

describe('RN-07-AC2 camino feliz', function () {
    it('RN-07-AC2: crear → solicitar → aprobar → despachar → recibir: cada paso 200, registra usuario y fecha de cada acción y termina RECIBIDO', function () {
        $e = rn07Escenario([10, 4]);

        rn07Congelar(0, '08:00:00');
        $creadoEn = now();
        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));
        $r->assertCreated();
        $id = cuerpoDatos($r)['id'];

        $pasos = [
            ['solicitar', 'creador', 0, '09:00:00', 'SOLICITADO', 'solicitado'],
            ['aprobar', 'regente', 0, '10:15:00', 'APROBADO', 'aprobado'],
            ['despachar', 'despachador', 0, '11:30:00', 'EN_TRANSITO', 'despachado'],
            ['recibir', 'receptor', 1, '07:45:00', 'RECIBIDO', 'recibido'],
        ];

        $registro = [];
        foreach ($pasos as [$accion, $quien, $dia, $hora, $estado, $prefijo]) {
            rn07Congelar($dia, $hora);
            $registro[$prefijo] = [now(), $e[$quien]->id];

            $r = rn07Hacer($e, $id, $accion);

            expect($r->getStatusCode())->toBe(200, "{$accion}: ".$r->getContent())
                ->and(cuerpoDatos($r)['estado'] ?? null)->toBe($estado)
                ->and(rn07Estado($id))->toBe($estado);
        }

        $fila = rn07Fila($id);
        expect((int) $fila->creado_por_id)->toBe($e['creador']->id)
            ->and(Carbon::parse($fila->created_at)->equalTo($creadoEn))->toBeTrue('created_at')
            ->and($fila->anulado_por_id)->toBeNull()
            ->and($fila->anulado_en)->toBeNull()
            ->and($fila->motivo_anulacion)->toBeNull();

        foreach ($registro as $prefijo => [$momento, $usuarioId]) {
            expect((int) $fila->{"{$prefijo}_por_id"})->toBe($usuarioId, "{$prefijo}_por_id")
                ->and($fila->{"{$prefijo}_en"})->not->toBeNull()
                ->and(Carbon::parse($fila->{"{$prefijo}_en"})->equalTo($momento))
                ->toBeTrue("{$prefijo}_en = {$fila->{"{$prefijo}_en"}} y se esperaba {$momento}");
        }

        // Efecto completo: origen −cantidad, destino +cantidad en el mismo lote, 2 salidas y 2 entradas.
        foreach ($e['lineas'] as $l) {
            expect(rn07StockEn($e['origen'], $l['lote']))->toBe(15)
                ->and(rn07StockEn($e['destino'], $l['lote']))->toBe($l['cantidad']);
        }
        $movs = rn07Movs($id);
        expect($movs->where('tipo', 'SALIDA_TRASLADO'))->toHaveCount(2)
            ->and($movs->where('tipo', 'ENTRADA_TRASLADO'))->toHaveCount(2)
            ->and($movs)->toHaveCount(4)
            ->and(rn07Discrepancias($id))->toHaveCount(0);

        // El detalle refleja el estado final y lo recibido.
        Sanctum::actingAs($e['creador']);
        $detalle = test()->getJson(RN07_URI."/{$id}");
        $detalle->assertOk();
        $d = cuerpoDatos($detalle);
        expect($d['estado'] ?? null)->toBe('RECIBIDO')
            ->and(collect($d['items'] ?? [])->pluck('cantidad_recibida')->all())->toBe([10, 4])
            ->and($d['discrepancias'] ?? null)->toBe([]);
    });

    it('RN-07-AC2 S-08: un usuario distinto del creador (auxiliar o regente) no puede solicitar el borrador → 403 NO_AUTORIZADO y sigue BORRADOR', function (string $rol) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'BORRADOR');
        $antes = rn07Instantanea();

        $r = rn07Accion(usuarioConRol($rol), $id, 'solicitar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn07Estado($id))->toBe('BORRADOR')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'otro auxiliar' => 'auxiliar_farmacia',
        'un regente' => 'regente_farmacia',
    ]);

    it('RN-07-AC2 S-16: quien despachó puede también recibir → 200 RECIBIDO', function () {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'EN_TRANSITO');

        $r = rn07Hacer($e, $id, 'recibir', $e['despachador']);

        $r->assertOk();
        $fila = rn07Fila($id);
        expect($fila->estado)->toBe('RECIBIDO')
            ->and((int) $fila->recibido_por_id)->toBe($e['despachador']->id)
            ->and((int) $fila->despachado_por_id)->toBe($e['despachador']->id);
    });
});

/*
|--------------------------------------------------------------------------
| AC3 — tabla completa de transiciones
|--------------------------------------------------------------------------
*/

describe('RN-07-AC3 tabla completa estado × acción', function () {
    it('RN-07-AC3: par (estado, acción) no permitido → 409 TRANSICION_INVALIDA; estado, ítems, stock y kardex sin cambios', function (string $estado, string $accion) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, $estado);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, $accion);

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn07Estado($id))->toBe($estado)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with('rn07_pares_invalidos');

    it('RN-07-AC3: par (estado, acción) permitido → 200 y el estado de destino de la tabla', function (string $estado, string $accion, string $hacia) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, $estado);

        $r = rn07Hacer($e, $id, $accion);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe($hacia)
            ->and(rn07Estado($id))->toBe($hacia);
    })->with([
        'BORRADOR × solicitar' => ['BORRADOR', 'solicitar', 'SOLICITADO'],
        'BORRADOR × editar' => ['BORRADOR', 'editar', 'BORRADOR'],
        'BORRADOR × anular' => ['BORRADOR', 'anular', 'ANULADO'],
        'SOLICITADO × aprobar' => ['SOLICITADO', 'aprobar', 'APROBADO'],
        'SOLICITADO × anular' => ['SOLICITADO', 'anular', 'ANULADO'],
        'APROBADO × despachar' => ['APROBADO', 'despachar', 'EN_TRANSITO'],
        'APROBADO × anular' => ['APROBADO', 'anular', 'ANULADO'],
        'EN_TRANSITO × recibir (todo)' => ['EN_TRANSITO', 'recibir', 'RECIBIDO'],
        'EN_TRANSITO × recibir (menos)' => ['EN_TRANSITO', 'recibir_parcial', 'RECIBIDO_PARCIAL'],
    ]);

    it('RN-07-AC3: la misma acción válida repetida (doble clic secuencial) → la segunda 409 TRANSICION_INVALIDA y no duplica efectos', function (string $estado, string $accion) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, $estado);

        rn07Hacer($e, $id, $accion)->assertOk();
        $antes = rn07Instantanea();

        esperarError(rn07Hacer($e, $id, $accion), 409, 'TRANSICION_INVALIDA');
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'solicitar dos veces' => ['BORRADOR', 'solicitar'],
        'aprobar dos veces' => ['SOLICITADO', 'aprobar'],
        'despachar dos veces' => ['APROBADO', 'despachar'],
        'recibir dos veces' => ['EN_TRANSITO', 'recibir'],
        'anular dos veces' => ['APROBADO', 'anular'],
    ]);

    it('RN-07-AC3: traslado inexistente → 404 en cada acción', function (string $accion) {
        $e = rn07Escenario([10]);
        rn07CrearOk($e);
        $antes = rn07Instantanea();

        $r = rn07Accion(rn07Actor($e, $accion), 999_999, $accion, $accion === 'anular' ? ['motivo' => RN07_MOTIVO] : []);

        $r->assertNotFound();
        expect(rn07Instantanea())->toEqual($antes);
    })->with(RN07_ACCIONES);
});

/*
|--------------------------------------------------------------------------
| AC4 / AC5 / AC6 — stock al despachar y recibir (cierra RN-06-AC1 para traslados)
|--------------------------------------------------------------------------
*/

describe('RN-07-AC4 RN-07-AC5 RN-07-AC6 stock y kardex del traslado', function () {
    it('RN-07-AC4 RN-06-AC1: despachar un APROBADO de 10 u. del lote L → origen −10, una SALIDA_TRASLADO con documento traslado + id, EN_TRANSITO y destino sin cambios', function (?int $destinoPrevio) {
        $e = rn07Escenario([10], stocks: [25], stocksDestino: [$destinoPrevio]);
        $l = $e['lineas'][0];
        $id = rn07Llevar($e, 'APROBADO');
        $kardexAntes = DB::table('movimientos_kardex')->count();
        $destinoAntes = DB::table('existencias')->where('bodega_id', $e['destino']->id)->orderBy('id')->get()->map(fn ($f) => (array) $f)->all();

        rn07Congelar(0, '11:00:00');
        $r = rn07Hacer($e, $id, 'despachar');

        $r->assertOk();
        expect(cuerpoDatos($r)['estado'] ?? null)->toBe('EN_TRANSITO')
            ->and(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07StockEn($e['origen'], $l['lote']))->toBe(15)
            ->and(DB::table('movimientos_kardex')->count())->toBe($kardexAntes + 1)
            ->and(DB::table('existencias')->where('bodega_id', $e['destino']->id)->orderBy('id')->get()->map(fn ($f) => (array) $f)->all())->toEqual($destinoAntes)
            ->and(rn07StockEn($e['destino'], $l['lote']))->toBe($destinoPrevio);

        $movs = rn07Movs($id);
        expect($movs)->toHaveCount(1);
        $m = $movs->first();
        expect($m->tipo)->toBe('SALIDA_TRASLADO')
            ->and((int) $m->existencia_id)->toBe($l['existencia']->id)
            ->and((int) $m->bodega_id)->toBe($e['origen']->id)
            ->and((int) $m->producto_id)->toBe($l['producto']->id)
            ->and((int) $m->lote_id)->toBe($l['lote']->id)
            ->and((int) $m->cantidad)->toBe(-10)
            ->and((int) $m->saldo_anterior)->toBe(25)
            ->and((int) $m->saldo_posterior)->toBe(15)
            ->and((int) $m->usuario_id)->toBe($e['despachador']->id)
            ->and($m->documento_tipo)->toBe('traslado')
            ->and((int) $m->documento_id)->toBe($id)
            ->and($m->created_at)->not->toBeNull()
            ->and(Carbon::parse($m->created_at)->equalTo(now()))->toBeTrue();

        rn07AfirmarCadena($l['existencia']->id);
    })->with([
        'destino sin existencia del lote' => [null],
        'destino con existencia previa del lote' => [4],
    ]);

    it('RN-07-AC4 RN-06-AC1: despachar varios ítems → una SALIDA_TRASLADO por ítem en su lote y cada origen baja lo suyo', function () {
        $e = rn07Escenario([10, 1, 7], stocks: [10, 5, 30]);
        $id = rn07Llevar($e, 'APROBADO');

        rn07Hacer($e, $id, 'despachar')->assertOk();

        $movs = rn07Movs($id);
        expect($movs)->toHaveCount(3)
            ->and($movs->pluck('tipo')->unique()->values()->all())->toBe(['SALIDA_TRASLADO']);
        foreach ($e['lineas'] as $l) {
            $mov = $movs->firstWhere('lote_id', $l['lote']->id);
            expect($mov)->not->toBeNull()
                ->and((int) $mov->cantidad)->toBe(-$l['cantidad'])
                ->and((int) $mov->existencia_id)->toBe($l['existencia']->id);
            rn07AfirmarCadena($l['existencia']->id);
        }
        expect(rn07StockEn($e['origen'], $e['lineas'][0]['lote']))->toBe(0)
            ->and(rn07StockEn($e['origen'], $e['lineas'][1]['lote']))->toBe(4)
            ->and(rn07StockEn($e['origen'], $e['lineas'][2]['lote']))->toBe(23);
    });

    it('RN-07-AC5 RN-06-AC1: recibir las 10 → destino +10 en el mismo lote L (creando la existencia si no existía), ENTRADA_TRASLADO con documento traslado + id, RECIBIDO y sin discrepancias', function (?int $destinoPrevio) {
        $e = rn07Escenario([10], stocks: [25], stocksDestino: [$destinoPrevio]);
        $l = $e['lineas'][0];
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $existenciasDestinoAntes = DB::table('existencias')->where('bodega_id', $e['destino']->id)->count();

        rn07Congelar(1, '09:00:00');
        $r = rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [10]));

        $r->assertOk();
        expect(cuerpoDatos($r)['estado'] ?? null)->toBe('RECIBIDO')
            ->and(rn07Estado($id))->toBe('RECIBIDO')
            ->and(rn07StockEn($e['destino'], $l['lote']))->toBe(($destinoPrevio ?? 0) + 10)
            ->and(rn07StockEn($e['origen'], $l['lote']))->toBe(15)
            ->and(DB::table('existencias')->where('bodega_id', $e['destino']->id)->count())
            ->toBe($existenciasDestinoAntes + ($destinoPrevio === null ? 1 : 0))
            ->and(rn07Discrepancias($id))->toHaveCount(0)
            ->and((int) rn07Items($id)->first()->cantidad_recibida)->toBe(10);

        $existenciaDestino = DB::table('existencias')->where('bodega_id', $e['destino']->id)->where('lote_id', $l['lote']->id)->first();
        expect((int) $existenciaDestino->producto_id)->toBe($l['producto']->id);

        $entradas = rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO')->values();
        expect($entradas)->toHaveCount(1)
            ->and(rn07Movs($id))->toHaveCount(2);
        $m = $entradas->first();
        expect((int) $m->existencia_id)->toBe((int) $existenciaDestino->id)
            ->and((int) $m->bodega_id)->toBe($e['destino']->id)
            ->and((int) $m->producto_id)->toBe($l['producto']->id)
            ->and((int) $m->lote_id)->toBe($l['lote']->id)
            ->and((int) $m->cantidad)->toBe(10)
            ->and((int) $m->saldo_anterior)->toBe($destinoPrevio ?? 0)
            ->and((int) $m->saldo_posterior)->toBe(($destinoPrevio ?? 0) + 10)
            ->and((int) $m->usuario_id)->toBe($e['receptor']->id)
            ->and($m->documento_tipo)->toBe('traslado')
            ->and((int) $m->documento_id)->toBe($id)
            ->and(Carbon::parse($m->created_at)->equalTo(now()))->toBeTrue();

        rn07AfirmarCadena((int) $existenciaDestino->id);
        rn07AfirmarCadena($l['existencia']->id);
    })->with([
        'destino sin existencia del lote' => [null],
        'destino con existencia previa del lote' => [4],
        'destino con existencia previa en 0' => [0],
    ]);

    it('RN-07-AC5 S-12: un lote que vence durante el tránsito se acepta al recibir → 200 RECIBIDO y entra al destino', function (int $diasDespues) {
        $e = rn07Escenario([10], diasVence: [2]);
        $l = $e['lineas'][0];
        $id = rn07Llevar($e, 'EN_TRANSITO');

        rn07Congelar($diasDespues);
        $r = rn07Hacer($e, $id, 'recibir');

        $r->assertOk();
        expect(rn07Estado($id))->toBe('RECIBIDO')
            ->and(rn07StockEn($e['destino'], $l['lote']))->toBe(10)
            ->and(rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO'))->toHaveCount(1);
    })->with([
        'vence el día de la recepción' => 2,
        'venció el día anterior a la recepción' => 3,
    ]);

    it('RN-07-AC6: recibir menos de lo despachado → destino sube lo recibido, RECIBIDO_PARCIAL y una discrepancia PENDIENTE por el faltante ligada al ítem', function (int $recibido, int $faltante) {
        $e = rn07Escenario([10], stocks: [25]);
        $l = $e['lineas'][0];
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $item = rn07Items($id)->first();

        $r = rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [$recibido]));

        $r->assertOk();
        expect(cuerpoDatos($r)['estado'] ?? null)->toBe('RECIBIDO_PARCIAL')
            ->and(rn07Estado($id))->toBe('RECIBIDO_PARCIAL')
            ->and((int) rn07Items($id)->first()->cantidad_recibida)->toBe($recibido)
            ->and((int) (rn07StockEn($e['destino'], $l['lote']) ?? 0))->toBe($recibido)
            ->and(rn07StockEn($e['origen'], $l['lote']))->toBe(15);

        $discrepancias = rn07Discrepancias($id);
        expect($discrepancias)->toHaveCount(1)
            ->and((int) $discrepancias->first()->traslado_item_id)->toBe((int) $item->id)
            ->and((int) $discrepancias->first()->cantidad)->toBe($faltante)
            ->and($discrepancias->first()->estado)->toBe('PENDIENTE');

        $entradas = rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO')->values();
        if ($recibido === 0) {
            // Recibir 0 no es un movimiento (kardex: cantidad <> 0).
            expect($entradas)->toHaveCount(0);
        } else {
            expect($entradas)->toHaveCount(1)
                ->and((int) $entradas->first()->cantidad)->toBe($recibido)
                ->and((int) $entradas->first()->documento_id)->toBe($id);
            rn07AfirmarCadena((int) $entradas->first()->existencia_id);
        }
    })->with([
        'recibe 7 de 10 (caso de la spec)' => [7, 3],
        'recibe 9 de 10 (falta 1)' => [9, 1],
        'recibe 1 de 10' => [1, 9],
        'recibe 0 de 10 (discrepancia por el total)' => [0, 10],
    ]);

    it('RN-07-AC6: varios ítems, uno completo y otro con 0 recibido → RECIBIDO_PARCIAL, discrepancia solo del ítem en 0 por su total y ENTRADA solo del completo', function () {
        $e = rn07Escenario([10, 4, 6]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $items = rn07Items($id);

        rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [10, 0, 5]))->assertOk();

        expect(rn07Estado($id))->toBe('RECIBIDO_PARCIAL');
        $discrepancias = rn07Discrepancias($id)->keyBy('traslado_item_id');
        expect($discrepancias)->toHaveCount(2)
            ->and((int) $discrepancias[$items[1]->id]->cantidad)->toBe(4)
            ->and((int) $discrepancias[$items[2]->id]->cantidad)->toBe(1)
            ->and($discrepancias->pluck('estado')->unique()->values()->all())->toBe(['PENDIENTE'])
            ->and($discrepancias->has($items[0]->id))->toBeFalse();

        $entradas = rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO');
        expect($entradas)->toHaveCount(2)
            ->and($entradas->pluck('lote_id')->map(fn ($v): int => (int) $v)->sort()->values()->all())
            ->toBe(collect([$e['lineas'][0]['lote']->id, $e['lineas'][2]['lote']->id])->sort()->values()->all())
            ->and(rn07StockEn($e['destino'], $e['lineas'][0]['lote']))->toBe(10)
            // S-70: recibir 0 no crea la existencia en destino.
            ->and(rn07StockEn($e['destino'], $e['lineas'][1]['lote']))->toBeNull()
            ->and(rn07StockEn($e['destino'], $e['lineas'][2]['lote']))->toBe(5);
    });

    it('RN-07-AC6: recibir 0 de TODOS los ítems → RECIBIDO_PARCIAL, una discrepancia por el total de cada ítem y ningún movimiento de entrada', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');

        rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [0, 0]))->assertOk();

        expect(rn07Estado($id))->toBe('RECIBIDO_PARCIAL')
            ->and(rn07Discrepancias($id)->pluck('cantidad')->map(fn ($v): int => (int) $v)->all())->toBe([10, 4])
            ->and(rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO'))->toHaveCount(0)
            ->and(rn07Movs($id)->where('tipo', 'SALIDA_TRASLADO'))->toHaveCount(2);
    });
});

/*
|--------------------------------------------------------------------------
| AC7 — recepción inválida
|--------------------------------------------------------------------------
*/

describe('RN-07-AC7 recepción inválida', function () {
    it('RN-07-AC7: recibido mayor que lo despachado → 422 CANTIDAD_RECIBIDA_EXCEDE, sigue EN_TRANSITO y nada cambia (todo o nada)', function (array $recibidas) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, $recibidas));

        esperarError($r, 422, 'CANTIDAD_RECIBIDA_EXCEDE');
        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'primer ítem despachado + 1' => [[11, 4]],
        'segundo ítem despachado + 1 (el primero válido)' => [[10, 5]],
        'muy por encima' => [[1000, 4]],
    ]);

    it('RN-07-AC7: cuerpo de recepción mal formado → 422 VALIDACION, sigue EN_TRANSITO y nada cambia', function (Closure $cuerpo) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $otro = rn07CrearOk($e);
        $ids = rn07IdsItems($id);
        $ajeno = rn07IdsItems($otro)[0];
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'recibir', cuerpo: $cuerpo($ids, $ajeno));

        rn07AfirmarValidacion($r);
        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'cantidad negativa' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => -1],
            ['traslado_item_id' => $ids[1], 'cantidad_recibida' => 4],
        ]]],
        'falta un ítem' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 10],
        ]]],
        'sin ítems' => [fn (array $ids, int $ajeno): array => ['items' => []]],
        'sin la clave items' => [fn (array $ids, int $ajeno): array => []],
        'cantidad decimal (S-07)' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 6.5],
            ['traslado_item_id' => $ids[1], 'cantidad_recibida' => 4],
        ]]],
        'cantidad nula' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => null],
            ['traslado_item_id' => $ids[1], 'cantidad_recibida' => 4],
        ]]],
        'ítem repetido en lugar del segundo' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 5],
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 5],
        ]]],
        'ítem de otro traslado en lugar del segundo' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 10],
            ['traslado_item_id' => $ajeno, 'cantidad_recibida' => 4],
        ]]],
        'todos los ítems más uno de otro traslado' => [fn (array $ids, int $ajeno): array => ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 10],
            ['traslado_item_id' => $ids[1], 'cantidad_recibida' => 4],
            ['traslado_item_id' => $ajeno, 'cantidad_recibida' => 1],
        ]]],
    ]);
});

/*
|--------------------------------------------------------------------------
| AC8 — anulación
|--------------------------------------------------------------------------
*/

describe('RN-07-AC8 anulación', function () {
    it('RN-07-AC8: anular con motivo desde BORRADOR, SOLICITADO o APROBADO (creador o regente) → 200 ANULADO, registra quién, cuándo y el motivo, sin kardex ni cambio de stock', function (string $estado, string $quien) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, $estado);
        $actor = $quien === 'creador' ? $e['creador'] : usuarioConRol('regente_farmacia');
        $antes = rn07Instantanea();

        rn07Congelar(0, '16:20:00');
        $r = rn07Hacer($e, $id, 'anular', $actor, ['motivo' => RN07_MOTIVO]);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('ANULADO');

        $fila = rn07Fila($id);
        expect($fila->estado)->toBe('ANULADO')
            ->and((int) $fila->anulado_por_id)->toBe($actor->id)
            ->and(Carbon::parse($fila->anulado_en)->equalTo(now()))->toBeTrue()
            ->and($fila->motivo_anulacion)->toBe(RN07_MOTIVO)
            ->and($fila->despachado_por_id)->toBeNull();

        $despues = rn07Instantanea();
        expect($despues['existencias'])->toEqual($antes['existencias'])
            ->and($despues['kardex'])->toEqual($antes['kardex'])
            ->and($despues['traslado_items'])->toEqual($antes['traslado_items'])
            ->and($despues['discrepancias'])->toBe([])
            ->and(rn07Movs($id))->toHaveCount(0);
    })->with([
        'BORRADOR' => 'BORRADOR',
        'SOLICITADO' => 'SOLICITADO',
        'APROBADO' => 'APROBADO',
    ])->with([
        'el creador (auxiliar)' => 'creador',
        'un regente que no lo creó' => 'regente',
    ]);

    it('RN-07-AC8: anular sin motivo válido → 422 VALIDACION y el estado no cambia', function (string $estado, array $cuerpo) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, $estado);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'anular', cuerpo: $cuerpo);

        rn07AfirmarValidacion($r);
        expect(rn07Estado($id))->toBe($estado)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'BORRADOR' => 'BORRADOR',
        'SOLICITADO' => 'SOLICITADO',
        'APROBADO' => 'APROBADO',
    ])->with([
        'sin motivo' => [[]],
        'motivo vacío' => [['motivo' => '']],
        'motivo solo espacios' => [['motivo' => '     ']],
        'motivo nulo' => [['motivo' => null]],
    ]);

    it('RN-07-AC8 S-09: anular un EN_TRANSITO → 409 TRANSICION_INVALIDA; el stock sigue fuera del origen y no se devuelve nada', function (string $quien) {
        $e = rn07Escenario([10], stocks: [25]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $actor = $quien === 'creador' ? $e['creador'] : usuarioConRol('regente_farmacia');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'anular', $actor, ['motivo' => RN07_MOTIVO]);

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07StockEn($e['origen'], $e['lineas'][0]['lote']))->toBe(15)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'el creador' => 'creador',
        'un regente' => 'regente',
    ]);

    it('RN-07-AC8: un auxiliar que no creó el traslado no puede anularlo → 403 NO_AUTORIZADO y nada cambia', function (string $estado) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, $estado);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'anular', usuarioConRol('auxiliar_farmacia'), ['motivo' => RN07_MOTIVO]);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn07Estado($id))->toBe($estado)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['BORRADOR', 'SOLICITADO', 'APROBADO']);
});

/*
|--------------------------------------------------------------------------
| AC9 — validación de creación y edición de ítems
|--------------------------------------------------------------------------
*/

describe('RN-07-AC9 validación de creación y edición', function () {
    it('RN-07-AC9: crear con datos inválidos → 422 VALIDACION y no se crea nada', function (Closure $mutar) {
        $e = rn07Escenario([10, 4]);
        $cuerpo = $mutar(rn07CuerpoCrear($e), $e);
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], $cuerpo);

        rn07AfirmarValidacion($r);
        expect(rn07Instantanea())->toEqual($antes)
            ->and(DB::table('traslados')->count())->toBe(0);
    })->with([
        'origen = destino' => [fn (array $c, array $e): array => ['bodega_destino_id' => $e['origen']->id] + $c],
        'cantidad 0' => [fn (array $c): array => array_replace_recursive($c, ['items' => [1 => ['cantidad' => 0]]])],
        'cantidad negativa' => [fn (array $c): array => array_replace_recursive($c, ['items' => [0 => ['cantidad' => -3]]])],
        'cantidad decimal (S-07)' => [fn (array $c): array => array_replace_recursive($c, ['items' => [0 => ['cantidad' => 2.5]]])],
        'sin ítems' => [fn (array $c): array => ['items' => []] + $c],
        'bodega origen inexistente' => [fn (array $c): array => ['bodega_origen_id' => 999_999] + $c],
        'bodega destino inexistente' => [fn (array $c): array => ['bodega_destino_id' => 999_999] + $c],
        'lote inexistente' => [fn (array $c): array => array_replace_recursive($c, ['items' => [0 => ['lote_id' => 999_999]]])],
        'sin bodega destino' => [fn (array $c): array => array_diff_key($c, ['bodega_destino_id' => true])],
    ]);

    it('RN-07-AC9 S-64: ítem cuyo lote no pertenece al producto → 422 VALIDACION y no se crea nada', function () {
        $e = rn07Escenario([10, 4]);
        $cuerpo = rn07CuerpoCrear($e);
        // El lote del segundo ítem con el producto del primero.
        $cuerpo['items'][1]['producto_id'] = $e['lineas'][0]['producto']->id;
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], $cuerpo);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('items.1.lote_id')
            ->and(rn07Instantanea())->toEqual($antes)
            ->and(DB::table('traslados')->count())->toBe(0);
    });

    it('RN-07-AC9: en BORRADOR el creador edita los ítems → 200, los ítems quedan reemplazados y no hay movimientos', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'BORRADOR');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'editar', cuerpo: ['items' => [[
            'producto_id' => $e['lineas'][1]['producto']->id,
            'lote_id' => $e['lineas'][1]['lote']->id,
            'cantidad' => 2,
        ]]]);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('BORRADOR');
        $items = rn07Items($id);
        expect($items)->toHaveCount(1)
            ->and((int) $items[0]->lote_id)->toBe($e['lineas'][1]['lote']->id)
            ->and((int) $items[0]->cantidad)->toBe(2)
            ->and(rn07Estado($id))->toBe('BORRADOR');
        $despues = rn07Instantanea();
        expect($despues['existencias'])->toEqual($antes['existencias'])
            ->and($despues['kardex'])->toEqual($antes['kardex']);
    });

    it('RN-07-AC9: editar ítems en BORRADOR con datos inválidos → 422 VALIDACION y los ítems no cambian', function (Closure $items) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'BORRADOR');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'editar', cuerpo: ['items' => $items($e)]);

        rn07AfirmarValidacion($r);
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'cantidad 0' => [fn (array $e): array => rn07ItemsCuerpo($e, fn (): int => 0)],
        'cantidad negativa' => [fn (array $e): array => rn07ItemsCuerpo($e, fn (): int => -1)],
        'lista vacía' => [fn (): array => []],
    ]);

    it('RN-07-AC9: editar ítems fuera de BORRADOR → 409 TRANSICION_INVALIDA y los ítems no cambian', function (string $estado) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, $estado);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'editar');

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn07Estado($id))->toBe($estado)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'ANULADO']);

    it('RN-07-AC9: la BD rechaza origen = destino, cantidad <= 0, cantidad_recibida fuera de [0, cantidad] y discrepancia <= 0 (CHECK)', function (string $caso) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $item = rn07Items($id)->first();

        $sqlstate = null;
        try {
            DB::transaction(function () use ($caso, $id, $item, $e): void {
                match ($caso) {
                    'origen = destino' => DB::table('traslados')->where('id', $id)->update(['bodega_destino_id' => $e['origen']->id]),
                    'cantidad 0' => DB::table('traslado_items')->where('id', $item->id)->update(['cantidad' => 0]),
                    'cantidad negativa' => DB::table('traslado_items')->where('id', $item->id)->update(['cantidad' => -1]),
                    'recibida negativa' => DB::table('traslado_items')->where('id', $item->id)->update(['cantidad_recibida' => -1]),
                    'recibida > cantidad' => DB::table('traslado_items')->where('id', $item->id)->update(['cantidad_recibida' => 11]),
                    'estado fuera de los 7' => DB::table('traslados')->where('id', $id)->update(['estado' => 'PERDIDO']),
                    'discrepancia 0' => DB::table('discrepancias')->insert([
                        'traslado_item_id' => $item->id, 'cantidad' => 0, 'estado' => 'PENDIENTE',
                        'created_at' => now(), 'updated_at' => now(),
                    ]),
                };
            });
        } catch (QueryException $ex) {
            $sqlstate = (string) $ex->getCode();
        }

        expect($sqlstate)->toBe('23514')
            ->and(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and((int) rn07Items($id)->first()->cantidad)->toBe(10)
            ->and(rn07Discrepancias($id))->toHaveCount(0);
    })->with([
        'origen = destino',
        'cantidad 0',
        'cantidad negativa',
        'recibida negativa',
        'recibida > cantidad',
        'estado fuera de los 7',
        'discrepancia 0',
    ]);
});

/*
|--------------------------------------------------------------------------
| AC10 — despacho sin stock suficiente
|--------------------------------------------------------------------------
*/

describe('RN-07-AC10 despacho sin stock suficiente', function () {
    it('RN-07-AC10: APROBADO con un ítem sin stock suficiente en origen → 409 STOCK_INSUFICIENTE, sigue APROBADO y ningún ítem se descuenta', function (array $stockTrasAprobar) {
        $e = rn07Escenario([10, 4, 6], stocks: [30, 30, 30]);
        $id = rn07Llevar($e, 'APROBADO');
        foreach ($stockTrasAprobar as $i => $nuevo) {
            rn07AjustarA($e['lineas'][$i]['existencia'], $nuevo);
        }
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'despachar');

        esperarError($r, 409, 'STOCK_INSUFICIENTE');
        $fila = rn07Fila($id);
        expect($fila->estado)->toBe('APROBADO')
            ->and($fila->despachado_por_id)->toBeNull()
            ->and($fila->despachado_en)->toBeNull()
            ->and(rn07Movs($id))->toHaveCount(0)
            ->and(rn07Instantanea())->toEqual($antes);
        foreach ($e['lineas'] as $l) {
            rn07AfirmarCadena($l['existencia']->id);
        }
    })->with([
        'falta en el primer ítem (stock − 1)' => [[0 => 9]],
        'falta en el ítem del medio' => [[1 => 3]],
        'falta en el último ítem (los anteriores alcanzan)' => [[2 => 5]],
        'el último ítem sin stock' => [[2 => 0]],
        'faltan todos' => [[0 => 1, 1 => 1, 2 => 1]],
    ]);

    it('RN-07-AC10: APROBADO cuya existencia en origen ya no existe para el lote → 409 STOCK_INSUFICIENTE y nada cambia', function () {
        $e = rn07Escenario([10]);
        $otraBodega = Bodega::factory()->create();
        // El borrador nombra un lote que la bodega origen nunca tuvo (solo hay stock en otra bodega).
        $lote = Lote::factory()->create(['producto_id' => $e['lineas'][0]['producto']->id, 'fecha_vencimiento' => rn07Dia(300)]);
        rn07Stock($otraBodega, $lote, 50);
        $e['lineas'][0]['lote'] = $lote;
        $id = rn07Llevar($e, 'APROBADO');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'despachar');

        esperarError($r, 409, 'STOCK_INSUFICIENTE');
        expect(rn07Estado($id))->toBe('APROBADO')
            ->and(rn07Instantanea())->toEqual($antes);
    });

    it('RN-07-AC10: frontera, stock exactamente igual a lo pedido → 200 EN_TRANSITO y el origen queda en 0', function () {
        $e = rn07Escenario([10, 4], stocks: [10, 4]);
        $id = rn07Llevar($e, 'APROBADO');

        rn07Hacer($e, $id, 'despachar')->assertOk();

        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07StockEn($e['origen'], $e['lineas'][0]['lote']))->toBe(0)
            ->and(rn07StockEn($e['origen'], $e['lineas'][1]['lote']))->toBe(0)
            ->and(rn07Movs($id))->toHaveCount(2);
    });
});

/*
|--------------------------------------------------------------------------
| AC12 — resolución de discrepancias
|--------------------------------------------------------------------------
*/

/**
 * Traslado RECIBIDO_PARCIAL de 10 u. con `recibido` recibidas; devuelve el escenario, el id del
 * traslado y la discrepancia pendiente.
 *
 * @return array{0: array<string, mixed>, 1: int, 2: object}
 */
function rn07ConDiscrepancia(int $recibido = 7): array
{
    $e = rn07Escenario([10], stocks: [25]);
    $id = rn07Llevar($e, 'EN_TRANSITO');
    rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [$recibido]))->assertOk();
    $discrepancia = rn07Discrepancias($id)->first();
    expect($discrepancia)->not->toBeNull();

    return [$e, $id, $discrepancia];
}

/** @param array<string, mixed> $cuerpo */
function rn07Resolver(User $actor, int $discrepanciaId, array $cuerpo): TestResponse
{
    Sanctum::actingAs($actor);

    return test()->postJson(RN07_URI_DISCREPANCIAS."/{$discrepanciaId}/resolver", $cuerpo);
}

describe('RN-07-AC12 resolución de discrepancias', function () {
    it('RN-07-AC12: un regente resuelve una discrepancia PENDIENTE → 200 RESUELTA; REINTEGRO_ORIGEN y ENTREGA_DESTINO crean un AJUSTE positivo en su bodega y PERDIDA no mueve stock', function (string $tipo, int $recibido) {
        [$e, $id, $d] = rn07ConDiscrepancia($recibido);
        $l = $e['lineas'][0];
        $faltante = 10 - $recibido;
        $origenAntes = rn07StockEn($e['origen'], $l['lote']);
        $destinoAntes = rn07StockEn($e['destino'], $l['lote']) ?? 0;
        $kardexAntes = DB::table('movimientos_kardex')->orderBy('id')->get();
        $resolutor = $e['regente'];

        rn07Congelar(2, '10:30:00');
        $r = rn07Resolver($resolutor, (int) $d->id, ['tipo' => $tipo, 'motivo' => RN07_MOTIVO_RESOLUCION]);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('RESUELTA');

        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        expect($fila->estado)->toBe('RESUELTA')
            ->and($fila->tipo_resolucion)->toBe($tipo)
            ->and($fila->motivo)->toBe(RN07_MOTIVO_RESOLUCION)
            ->and((int) $fila->resuelto_por_id)->toBe($resolutor->id)
            ->and(Carbon::parse($fila->resuelto_en)->equalTo(now()))->toBeTrue()
            ->and((int) $fila->cantidad)->toBe($faltante)
            // El traslado es final: no cambia de estado al resolver.
            ->and(rn07Estado($id))->toBe('RECIBIDO_PARCIAL');

        $nuevos = DB::table('movimientos_kardex')->whereNotIn('id', $kardexAntes->pluck('id'))->get();

        if ($tipo === 'PERDIDA') {
            expect($nuevos)->toHaveCount(0)
                ->and(rn07StockEn($e['origen'], $l['lote']))->toBe($origenAntes)
                ->and((int) (rn07StockEn($e['destino'], $l['lote']) ?? 0))->toBe($destinoAntes);

            return;
        }

        $bodega = $tipo === 'REINTEGRO_ORIGEN' ? $e['origen'] : $e['destino'];
        expect($nuevos)->toHaveCount(1);
        $m = $nuevos->first();
        expect($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->cantidad)->toBe($faltante)
            ->and((int) $m->bodega_id)->toBe($bodega->id)
            ->and((int) $m->lote_id)->toBe($l['lote']->id)
            ->and((int) $m->producto_id)->toBe($l['producto']->id)
            ->and((int) $m->usuario_id)->toBe($resolutor->id)
            ->and($m->motivo)->not->toBeNull()
            ->and(mb_strlen(trim((string) $m->motivo)))->toBeGreaterThanOrEqual(10);

        if ($tipo === 'REINTEGRO_ORIGEN') {
            expect(rn07StockEn($e['origen'], $l['lote']))->toBe($origenAntes + $faltante)
                ->and((int) (rn07StockEn($e['destino'], $l['lote']) ?? 0))->toBe($destinoAntes);
        } else {
            expect(rn07StockEn($e['destino'], $l['lote']))->toBe($destinoAntes + $faltante)
                ->and(rn07StockEn($e['origen'], $l['lote']))->toBe($origenAntes);
        }
        rn07AfirmarCadena((int) $m->existencia_id);
    })->with([
        'PERDIDA' => 'PERDIDA',
        'REINTEGRO_ORIGEN' => 'REINTEGRO_ORIGEN',
        'ENTREGA_DESTINO' => 'ENTREGA_DESTINO',
    ])->with([
        'recibidas 7 (faltan 3)' => 7,
        'recibidas 0 (falta todo; el destino puede no tener existencia)' => 0,
    ]);

    it('RN-07-AC12: un auxiliar no puede resolver → 403 NO_AUTORIZADO y la discrepancia sigue PENDIENTE sin movimientos', function (string $tipo) {
        [$e, $id, $d] = rn07ConDiscrepancia();
        $antes = rn07Instantanea();

        $r = rn07Resolver($e['creador'], (int) $d->id, ['tipo' => $tipo, 'motivo' => RN07_MOTIVO_RESOLUCION]);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(DB::table('discrepancias')->where('id', $d->id)->value('estado'))->toBe('PENDIENTE')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['PERDIDA', 'REINTEGRO_ORIGEN', 'ENTREGA_DESTINO']);

    it('RN-07-AC12: una discrepancia ya resuelta no se resuelve otra vez → 409 TRANSICION_INVALIDA sin movimientos ni cambios', function (string $primero, string $segundo) {
        [$e, $id, $d] = rn07ConDiscrepancia();
        rn07Resolver($e['regente'], (int) $d->id, ['tipo' => $primero, 'motivo' => RN07_MOTIVO_RESOLUCION])->assertOk();
        $antes = rn07Instantanea();

        $r = rn07Resolver(usuarioConRol('regente_farmacia'), (int) $d->id, ['tipo' => $segundo, 'motivo' => RN07_MOTIVO_RESOLUCION.' (segunda)']);

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'PERDIDA y luego REINTEGRO_ORIGEN' => ['PERDIDA', 'REINTEGRO_ORIGEN'],
        'REINTEGRO_ORIGEN dos veces' => ['REINTEGRO_ORIGEN', 'REINTEGRO_ORIGEN'],
        'ENTREGA_DESTINO y luego PERDIDA' => ['ENTREGA_DESTINO', 'PERDIDA'],
    ]);

    it('RN-07-AC12: tipo fuera de {PERDIDA, REINTEGRO_ORIGEN, ENTREGA_DESTINO} o sin motivo → 422 VALIDACION y sigue PENDIENTE', function (array $cuerpo) {
        [$e, $id, $d] = rn07ConDiscrepancia();
        $antes = rn07Instantanea();

        $r = rn07Resolver($e['regente'], (int) $d->id, $cuerpo);

        rn07AfirmarValidacion($r);
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'tipo desconocido' => [['tipo' => 'DONACION', 'motivo' => RN07_MOTIVO_RESOLUCION]],
        'tipo en minúsculas' => [['tipo' => 'perdida', 'motivo' => RN07_MOTIVO_RESOLUCION]],
        'sin tipo' => [['motivo' => RN07_MOTIVO_RESOLUCION]],
        'sin motivo' => [['tipo' => 'PERDIDA']],
        'motivo vacío' => [['tipo' => 'PERDIDA', 'motivo' => '']],
        'motivo solo espacios' => [['tipo' => 'PERDIDA', 'motivo' => '    ']],
    ]);

    it('RN-07-AC12: discrepancia inexistente → 404', function () {
        rn07ConDiscrepancia();
        $antes = rn07Instantanea();

        rn07Resolver(usuarioConRol('regente_farmacia'), 999_999, ['tipo' => 'PERDIDA', 'motivo' => RN07_MOTIVO_RESOLUCION])->assertNotFound();
        expect(rn07Instantanea())->toEqual($antes);
    });
});

/*
|--------------------------------------------------------------------------
| AC13 — roles
|--------------------------------------------------------------------------
*/

/**
 * Prepara el estado necesario para la acción y devuelve [uri, método, cuerpo].
 *
 * @param  array<string, mixed>  $e
 * @return array{0: string, 1: string, 2: array<string, mixed>}
 */
function rn07PeticionEscritura(array $e, string $accion): array
{
    if ($accion === 'crear') {
        return [RN07_URI, 'POST', rn07CuerpoCrear($e)];
    }
    if ($accion === 'resolver') {
        $id = rn07Llevar($e, 'EN_TRANSITO');
        rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [7]))->assertOk();

        return [RN07_URI_DISCREPANCIAS.'/'.rn07Discrepancias($id)->first()->id.'/resolver', 'POST', ['tipo' => 'ENTREGA_DESTINO', 'motivo' => RN07_MOTIVO_RESOLUCION]];
    }

    $estado = match ($accion) {
        'solicitar', 'anular', 'editar' => 'BORRADOR',
        'aprobar' => 'SOLICITADO',
        'despachar' => 'APROBADO',
        'recibir' => 'EN_TRANSITO',
    };
    $id = rn07Llevar($e, $estado);

    return $accion === 'editar'
        ? [RN07_URI."/{$id}/items", 'PUT', rn07CuerpoAccion($e, $id, 'editar')]
        : [RN07_URI."/{$id}/{$accion}", 'POST', rn07CuerpoAccion($e, $id, $accion)];
}

describe('RN-07-AC13 roles', function () {
    it('RN-07-AC13: medico, auditor y admin no pueden ninguna escritura → 403 NO_AUTORIZADO y nada cambia', function (string $rol, string $accion) {
        $e = rn07Escenario([10]);
        [$uri, $metodo, $cuerpo] = rn07PeticionEscritura($e, $accion);
        $antes = rn07Instantanea();

        Sanctum::actingAs(usuarioConRol($rol));
        $r = test()->json($metodo, $uri, $cuerpo);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'medico' => 'medico',
        'auditor' => 'auditor',
        'admin' => 'admin',
    ])->with(['crear', 'editar', 'solicitar', 'aprobar', 'despachar', 'recibir', 'anular', 'resolver']);

    it('RN-07-AC13: sin token → 401 NO_AUTENTICADO en lectura y escritura', function (string $metodo, string $uri) {
        rn07Escenario([10]);
        $antes = rn07Instantanea();

        $r = test()->json($metodo, $uri, []);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn07Instantanea())->toEqual($antes);
    })->with([
        'GET /traslados' => ['GET', RN07_URI],
        'GET /traslados/{id}' => ['GET', RN07_URI.'/1'],
        'POST /traslados' => ['POST', RN07_URI],
        'POST /traslados/{id}/despachar' => ['POST', RN07_URI.'/1/despachar'],
        'POST /discrepancias/{id}/resolver' => ['POST', RN07_URI_DISCREPANCIAS.'/1/resolver'],
    ]);

    it('RN-07-AC13: el auditor lista traslados y ve el detalle con ítems y discrepancias', function () {
        [$e, $id, $d] = rn07ConDiscrepancia(7);
        $otro = rn07CrearOk($e);
        $auditor = usuarioConRol('auditor');
        Sanctum::actingAs($auditor);

        $lista = test()->getJson(RN07_URI);
        $lista->assertOk();
        $ids = collect($lista->json('data'))->pluck('id')->all();
        expect($ids)->toContain($id)->toContain($otro);

        $detalle = test()->getJson(RN07_URI."/{$id}");
        $detalle->assertOk();
        $datos = cuerpoDatos($detalle);
        expect($datos['id'] ?? null)->toBe($id)
            ->and($datos['estado'] ?? null)->toBe('RECIBIDO_PARCIAL')
            ->and($datos['items'] ?? null)->toBeArray()->toHaveCount(1)
            ->and($datos['items'][0]['cantidad'] ?? null)->toBe(10)
            ->and($datos['items'][0]['cantidad_recibida'] ?? null)->toBe(7)
            ->and($datos['discrepancias'] ?? null)->toBeArray()->toHaveCount(1)
            ->and($datos['discrepancias'][0]['id'] ?? null)->toBe((int) $d->id)
            ->and($datos['discrepancias'][0]['cantidad'] ?? null)->toBe(3)
            ->and($datos['discrepancias'][0]['estado'] ?? null)->toBe('PENDIENTE')
            ->and($datos['discrepancias'][0]['traslado_item_id'] ?? null)->toBe((int) rn07Items($id)->first()->id);
    });

    it('RN-07-AC13: consultar traslados: auxiliar, regente y auditor 200; medico y admin 403 NO_AUTORIZADO', function (string $rol, bool $permitido) {
        $e = rn07Escenario([10]);
        $id = rn07CrearOk($e);
        Sanctum::actingAs(usuarioConRol($rol));

        $lista = test()->getJson(RN07_URI);
        $detalle = test()->getJson(RN07_URI."/{$id}");

        if ($permitido) {
            $lista->assertOk();
            $detalle->assertOk();
            expect(collect($lista->json('data'))->pluck('id')->all())->toContain($id)
                ->and(cuerpoDatos($detalle)['estado'] ?? null)->toBe('BORRADOR');
        } else {
            esperarError($lista, 403, 'NO_AUTORIZADO');
            esperarError($detalle, 403, 'NO_AUTORIZADO');
        }
    })->with([
        'auxiliar' => ['auxiliar_farmacia', true],
        'regente' => ['regente_farmacia', true],
        'auditor' => ['auditor', true],
        'medico' => ['medico', false],
        'admin' => ['admin', false],
    ]);
});

/*
|--------------------------------------------------------------------------
| RN-01-AC6 / RN-01-AC7 — lote vencido en traslados (S-01)
|--------------------------------------------------------------------------
*/

describe('RN-01-AC6 RN-01-AC7 lote vencido en traslados', function () {
    it('RN-01-AC6: crear un traslado con un ítem de lote vencido (incluido el que vence hoy, S-01) → 422 LOTE_VENCIDO con lote_id y no se crea nada', function (int $diasVence, int $posicion) {
        $e = rn07Escenario([10, 4], diasVence: $posicion === 0 ? [$diasVence, 200] : [200, $diasVence]);
        $vencido = $e['lineas'][$posicion]['lote'];
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect($r->json('error.detalles.lote_id'))->toBe($vencido->id)
            ->and(rn07Instantanea())->toEqual($antes)
            ->and(DB::table('traslados')->count())->toBe(0);
    })->with([
        'vence hoy' => 0,
        'venció ayer' => -1,
        'venció hace 30 días' => -30,
    ])->with([
        'primer ítem' => 0,
        'segundo ítem (el primero vigente)' => 1,
    ]);

    it('RN-01-AC6: frontera, un lote que vence mañana sí se traslada → 201 BORRADOR', function () {
        $e = rn07Escenario([10], diasVence: [1]);

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));

        $r->assertCreated();
        expect(cuerpoDatos($r)['estado'] ?? null)->toBe('BORRADOR')
            ->and(DB::table('traslados')->count())->toBe(1);
    });

    it('RN-01-AC6: editar los ítems de un BORRADOR para incluir un lote vencido → 422 LOTE_VENCIDO con lote_id y los ítems no cambian', function (int $diasVence) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'BORRADOR');
        $vencido = Lote::factory()->create(['producto_id' => $e['lineas'][0]['producto']->id, 'fecha_vencimiento' => rn07Dia($diasVence)]);
        rn07Stock($e['origen'], $vencido, 20);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'editar', cuerpo: ['items' => [
            ['producto_id' => $e['lineas'][0]['producto']->id, 'lote_id' => $vencido->id, 'cantidad' => 5],
        ]]);

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect($r->json('error.detalles.lote_id'))->toBe($vencido->id)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'vence hoy' => 0,
        'venció ayer' => -1,
    ]);

    it('RN-01-AC7: un traslado APROBADO cuyo lote venció después de aprobarse → despachar 422 LOTE_VENCIDO con lote_id, sigue APROBADO y sin movimientos', function (int $diasHastaDespacho, int $posicion) {
        // Los lotes vencen en 3 días; se aprueba hoy y se intenta despachar más tarde.
        $e = rn07Escenario([10, 4], diasVence: $posicion === 0 ? [3, 200] : [200, 3]);
        $vencido = $e['lineas'][$posicion]['lote'];
        $id = rn07Llevar($e, 'APROBADO');
        $antes = rn07Instantanea();

        rn07Congelar($diasHastaDespacho, '08:00:00');
        $r = rn07Hacer($e, $id, 'despachar');

        esperarError($r, 422, 'LOTE_VENCIDO');
        $fila = rn07Fila($id);
        expect($r->json('error.detalles.lote_id'))->toBe($vencido->id)
            ->and($fila->estado)->toBe('APROBADO')
            ->and($fila->despachado_por_id)->toBeNull()
            ->and(rn07Movs($id))->toHaveCount(0)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'el día que vence (S-01)' => 3,
        'un día después de vencer' => 4,
    ])->with([
        'primer ítem' => 0,
        'segundo ítem (el primero vigente)' => 1,
    ]);

    it('RN-01-AC7: frontera, despachar el día antes de que venza el lote → 200 EN_TRANSITO', function () {
        $e = rn07Escenario([10], diasVence: [3]);
        $id = rn07Llevar($e, 'APROBADO');

        rn07Congelar(2, '23:59:00');
        $r = rn07Hacer($e, $id, 'despachar');

        $r->assertOk();
        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07Movs($id))->toHaveCount(1);
    });

    it('RN-01-AC7: "hoy" es el día de Bogotá: a las 00:30 del día de vencimiento → 422 LOTE_VENCIDO; a las 23:30 del día anterior (ya día de vencimiento en UTC) → 200', function () {
        $e = rn07Escenario([10], diasVence: [3]);
        $id = rn07Llevar($e, 'APROBADO');
        $antes = rn07Instantanea();

        // 23:30 en Bogotá del día anterior = 04:30 UTC del día de vencimiento: con "hoy" en UTC se
        // rechazaría por error.
        test()->travelTo(Carbon::parse(rn07Dia(3).' 00:30:00', 'America/Bogota'));
        esperarError(rn07Hacer($e, $id, 'despachar'), 422, 'LOTE_VENCIDO');
        expect(rn07Instantanea())->toEqual($antes);

        test()->travelTo(Carbon::parse(rn07Dia(2).' 23:30:00', 'America/Bogota'));
        rn07Hacer($e, $id, 'despachar')->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| Supuestos S-63..S-70 y ramas defensivas del servicio
|--------------------------------------------------------------------------
*/

/** Traslado recargado de la BD (para llamar a los servicios directamente). */
function rn07Modelo(int $id): Traslado
{
    return Traslado::query()->findOrFail($id);
}

/** Ejecuta `$accion` y devuelve la excepción lanzada (o null). */
function rn07Captura(Closure $accion): ?Throwable
{
    try {
        $accion();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

describe('RN-07-AC9 S-64 origen = destino con tipos JSON distintos', function () {
    it('RN-07-AC9 S-64: origen y destino con el mismo id en tipos JSON distintos → 422 VALIDACION en bodega_destino_id y no se crea nada', function (Closure $ids) {
        $e = rn07Escenario([10]);
        [$origen, $destino] = $ids($e['origen']->id);
        $cuerpo = ['bodega_origen_id' => $origen, 'bodega_destino_id' => $destino] + rn07CuerpoCrear($e);
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], $cuerpo);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('bodega_destino_id')
            ->and(rn07Instantanea())->toEqual($antes)
            ->and(DB::table('traslados')->count())->toBe(0);
    })->with([
        'entero / cadena' => [fn (int $id): array => [$id, (string) $id]],
        'entero / decimal' => [fn (int $id): array => [$id, (float) $id]],
        'cadena / entero' => [fn (int $id): array => [(string) $id, $id]],
        'cadena con + / entero' => [fn (int $id): array => ['+'.$id, $id]],
    ]);

    it('RN-07-AC9 S-64: TrasladoService::crear llamado directamente con origen = destino → ValidationException en bodega_destino_id sin escribir', function () {
        $e = rn07Escenario([10]);
        $antes = rn07Instantanea();

        $ex = rn07Captura(fn () => app(TrasladoService::class)->crear(new DatosTraslado(
            usuarioId: $e['creador']->id,
            bodegaOrigenId: $e['origen']->id,
            bodegaDestinoId: $e['origen']->id,
            observaciones: null,
            items: [new ItemTraslado($e['lineas'][0]['producto']->id, $e['lineas'][0]['lote']->id, 10)],
        )));

        expect($ex)->toBeInstanceOf(ValidationException::class)
            ->and($ex->errors())->toHaveKey('bodega_destino_id')
            ->and(rn07Instantanea())->toEqual($antes);
    });
});

describe('RN-07-AC9 S-63 solo el creador edita los ítems', function () {
    it('RN-07-AC9 S-63: otro auxiliar o un regente editan los ítems de un BORRADOR ajeno → 403 NO_AUTORIZADO y los ítems no cambian', function (string $rol) {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'BORRADOR');
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'editar', actor: usuarioConRol($rol));

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn07Estado($id))->toBe('BORRADOR')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['auxiliar_farmacia', 'regente_farmacia']);

    it('RN-07-AC9 S-63: TrasladoService::editarItems llamado directamente por otro usuario → NoAutorizado y los ítems no cambian', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'BORRADOR');
        $antes = rn07Instantanea();

        $ex = rn07Captura(fn () => app(TrasladoService::class)->editarItems(
            rn07Modelo($id),
            $e['regente']->id,
            [new ItemTraslado($e['lineas'][0]['producto']->id, $e['lineas'][0]['lote']->id, 1)],
        ));

        expect($ex)->toBeInstanceOf(NoAutorizado::class)
            ->and(rn07Instantanea())->toEqual($antes);
    });

    it('RN-08-AC2 RN-07-AC8: TrasladoService::solicitar y ::anular llamados directamente por un usuario no autorizado → NoAutorizado y nada cambia', function (string $accion) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'BORRADOR');
        $otro = usuarioConRol('auxiliar_farmacia');
        $antes = rn07Instantanea();

        $servicio = app(TrasladoService::class);
        $ex = rn07Captura(fn () => match ($accion) {
            'solicitar' => $servicio->solicitar(rn07Modelo($id), $otro->id),
            'anular' => $servicio->anular(rn07Modelo($id), $otro, RN07_MOTIVO),
        });

        expect($ex)->toBeInstanceOf(NoAutorizado::class)
            ->and(rn07Estado($id))->toBe('BORRADOR')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['solicitar', 'anular']);
});

describe('RN-07-AC9 S-64 forma de los ítems', function () {
    it('RN-07-AC9 S-64: un lote repetido (también con tipos JSON distintos) al crear o al editar → 422 VALIDACION y nada cambia', function (string $via, Closure $lote) {
        $e = rn07Escenario([10, 4]);
        $id = $via === 'editar' ? rn07Llevar($e, 'BORRADOR') : null;
        $items = rn07ItemsCuerpo($e);
        $items[1] = ['producto_id' => $items[0]['producto_id'], 'lote_id' => $lote($items[0]['lote_id']), 'cantidad' => 2];
        $antes = rn07Instantanea();

        $r = $via === 'editar'
            ? rn07Hacer($e, $id, 'editar', cuerpo: ['items' => $items])
            : rn07Crear($e['creador'], ['items' => $items] + rn07CuerpoCrear($e));

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('items.1.lote_id')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['crear', 'editar'])->with([
        'mismo entero' => [fn (int $lote): int => $lote],
        'como cadena' => [fn (int $lote): string => (string) $lote],
        'como decimal' => [fn (int $lote): float => (float) $lote],
    ]);

    it('RN-07-AC9 S-64: 51 ítems → 422 VALIDACION en items y no se crea nada', function () {
        $e = rn07Escenario(array_fill(0, 51, 1), stocks: array_fill(0, 51, 1));
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('items')
            ->and(rn07Instantanea())->toEqual($antes);
    });

    it('RN-07-AC1 S-64: 50 ítems (el máximo) se aceptan → 201 BORRADOR con los 50 ítems', function () {
        $e = rn07Escenario(array_fill(0, 50, 1), stocks: array_fill(0, 50, 1));

        $id = rn07CrearOk($e);

        expect(rn07Items($id))->toHaveCount(50)
            ->and(rn07Items($id)->pluck('lote_id')->map(fn ($v): int => (int) $v)->all())
            ->toBe(array_map(fn (array $l): int => $l['lote']->id, $e['lineas']));
    });

    it('RN-07-AC9 S-64: la BD rechaza el mismo lote dos veces en un traslado (UNIQUE traslado_items_traslado_lote_unique, 23505)', function () {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'BORRADOR');
        $item = rn07Items($id)->first();

        $ex = rn07Captura(fn () => DB::transaction(fn () => DB::table('traslado_items')->insert([
            'traslado_id' => $id, 'producto_id' => $item->producto_id, 'lote_id' => $item->lote_id,
            'cantidad' => 1, 'created_at' => now(), 'updated_at' => now(),
        ])));

        expect($ex)->toBeInstanceOf(QueryException::class)
            ->and((string) $ex->getCode())->toBe('23505')
            ->and($ex->getMessage())->toContain('traslado_items_traslado_lote_unique')
            ->and(rn07Items($id))->toHaveCount(1);
    });

    it('RN-07-AC9 S-64: clave desconocida en la raíz o en un ítem → 422 VALIDACION y nada cambia', function (string $via, string $donde) {
        $e = rn07Escenario([10]);
        $id = $via === 'editar' ? rn07Llevar($e, 'BORRADOR') : null;
        $cuerpo = $via === 'editar' ? ['items' => rn07ItemsCuerpo($e)] : rn07CuerpoCrear($e);
        if ($donde === 'raíz') {
            $cuerpo['estado'] = 'APROBADO';
        } else {
            $cuerpo['items'][0]['precio'] = 1;
        }
        $antes = rn07Instantanea();

        $r = $via === 'editar' ? rn07Hacer($e, $id, 'editar', cuerpo: $cuerpo) : rn07Crear($e['creador'], $cuerpo);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey($donde === 'raíz' ? 'estado' : 'items.0')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['crear', 'editar'])->with(['raíz', 'ítem']);

    it('RN-07-AC7 RN-07-AC8 RN-07-AC12 S-64: clave desconocida en la raíz de recibir, anular o resolver → 422 VALIDACION y nada cambia', function (string $accion) {
        if ($accion === 'resolver') {
            [$e, $id, $d] = rn07ConDiscrepancia();
            $antes = rn07Instantanea();
            $r = rn07Resolver($e['regente'], (int) $d->id, ['tipo' => 'PERDIDA', 'motivo' => RN07_MOTIVO_RESOLUCION, 'extra' => 1]);
        } else {
            $e = rn07Escenario([10]);
            $id = rn07Llevar($e, $accion === 'recibir' ? 'EN_TRANSITO' : 'BORRADOR');
            $antes = rn07Instantanea();
            $r = rn07Hacer($e, $id, $accion, cuerpo: rn07CuerpoAccion($e, $id, $accion) + ['extra' => 1]);
        }

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('extra')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['recibir', 'anular', 'resolver']);

    it('RN-07-AC7 S-64: traslado_item_id repetido con tipos JSON distintos en la recepción → 422 VALIDACION y sigue EN_TRANSITO', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $ids = rn07IdsItems($id);
        $antes = rn07Instantanea();

        $r = rn07Hacer($e, $id, 'recibir', cuerpo: ['items' => [
            ['traslado_item_id' => $ids[0], 'cantidad_recibida' => 5],
            ['traslado_item_id' => (string) $ids[0], 'cantidad_recibida' => 5],
        ]]);

        rn07AfirmarValidacion($r);
        expect(rn07Estado($id))->toBe('EN_TRANSITO')
            ->and(rn07Instantanea())->toEqual($antes);
    });
});

describe('RN-07 S-65 bodegas activas solo al crear', function () {
    it('RN-07-AC9 S-65: bodega origen o destino inactiva al crear → 422 VALIDACION en ese campo y no se crea nada', function (string $campo) {
        $e = rn07Escenario([10]);
        $bodega = $campo === 'bodega_origen_id' ? $e['origen'] : $e['destino'];
        $bodega->forceFill(['activa' => false])->save();
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e));

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey($campo)
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['bodega_origen_id', 'bodega_destino_id']);

    it('RN-07-AC4 RN-07-AC5 RN-07-AC12 S-65: desactivar las bodegas tras crear no impide despachar, recibir ni resolver → 200', function () {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, 'APROBADO');
        $e['origen']->forceFill(['activa' => false])->save();
        $e['destino']->forceFill(['activa' => false])->save();
        $lote = $e['lineas'][0]['lote'];

        $despacho = rn07Hacer($e, $id, 'despachar');
        expect($despacho->getStatusCode())->toBe(200, $despacho->getContent())
            ->and(rn07StockEn($e['origen'], $lote))->toBe(15);

        $recepcion = rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [7]));
        expect($recepcion->getStatusCode())->toBe(200, $recepcion->getContent())
            ->and(rn07Estado($id))->toBe('RECIBIDO_PARCIAL')
            ->and(rn07StockEn($e['destino'], $lote))->toBe(7);

        $d = rn07Discrepancias($id)->first();
        $resolucion = rn07Resolver($e['regente'], (int) $d->id, ['tipo' => 'ENTREGA_DESTINO', 'motivo' => RN07_MOTIVO_RESOLUCION]);
        expect($resolucion->getStatusCode())->toBe(200, $resolucion->getContent())
            ->and(rn07StockEn($e['destino'], $lote))->toBe(10);
    });
});

describe('RN-07 S-66 precedencias', function () {
    it('RN-01-AC7 RN-07-AC10 S-66: despachar con un ítem vencido y otro sin stock → 422 LOTE_VENCIDO (antes que el 409) y nada cambia', function (int $vencido) {
        $sinStock = 1 - $vencido;
        $diasVence = [200, 200];
        $diasVence[$vencido] = 3;
        $e = rn07Escenario([10, 4], diasVence: $diasVence);
        $id = rn07Llevar($e, 'APROBADO');
        rn07AjustarA($e['lineas'][$sinStock]['existencia'], 0);
        $antes = rn07Instantanea();

        rn07Congelar(3);
        $r = rn07Hacer($e, $id, 'despachar');

        esperarError($r, 422, 'LOTE_VENCIDO');
        expect($r->json('error.detalles.lote_id'))->toBe($e['lineas'][$vencido]['lote']->id)
            ->and(rn07Estado($id))->toBe('APROBADO')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['vencido el primero' => 0, 'vencido el segundo' => 1]);

    it('RN-07-AC3 RN-07-AC7 S-66: recibir sobre un APROBADO con un ítem de menos → 422 VALIDACION (forma antes que el 409); con cuerpo válido → 409 TRANSICION_INVALIDA', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'APROBADO');
        $ids = rn07IdsItems($id);
        $antes = rn07Instantanea();

        $incompleta = rn07Hacer($e, $id, 'recibir', cuerpo: ['items' => [['traslado_item_id' => $ids[0], 'cantidad_recibida' => 10]]]);
        rn07AfirmarValidacion($incompleta);

        $valida = rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id));
        esperarError($valida, 409, 'TRANSICION_INVALIDA');

        expect(rn07Estado($id))->toBe('APROBADO')
            ->and(rn07Instantanea())->toEqual($antes);
    });
});

describe('RN-07-AC12 S-67 motivo del ajuste al resolver', function () {
    it('RN-07-AC12 S-67: el AJUSTE lleva el motivo «Discrepancia N del traslado M (TIPO): motivo» y la discrepancia guarda su movimiento_kardex_id', function (string $tipo) {
        [$e, $id, $d] = rn07ConDiscrepancia();
        $motivo = str_repeat('m', 400);

        $r = rn07Resolver($e['regente'], (int) $d->id, ['tipo' => $tipo, 'motivo' => $motivo]);

        expect($r->getStatusCode())->toBe(200, $r->getContent());
        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        $m = DB::table('movimientos_kardex')->where('id', $fila->movimiento_kardex_id)->first();
        expect($fila->motivo)->toBe($motivo)
            ->and($m)->not->toBeNull()
            ->and($m->tipo)->toBe('AJUSTE')
            ->and((int) $m->usuario_id)->toBe($e['regente']->id)
            ->and($m->motivo)->toBe("Discrepancia {$d->id} del traslado {$id} ({$tipo}): {$motivo}");
    })->with(['REINTEGRO_ORIGEN', 'ENTREGA_DESTINO']);

    it('RN-07-AC12 S-67: motivo de 401 caracteres → 422 VALIDACION en motivo y sigue PENDIENTE', function () {
        [$e, $id, $d] = rn07ConDiscrepancia();
        $antes = rn07Instantanea();

        $r = rn07Resolver($e['regente'], (int) $d->id, ['tipo' => 'ENTREGA_DESTINO', 'motivo' => str_repeat('m', 401)]);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('motivo')
            ->and(rn07Instantanea())->toEqual($antes);
    });
});

describe('RN-07-AC1 S-68 observaciones', function () {
    it('RN-07-AC1 S-68: observaciones válidas (hasta 1000 caracteres, con salto de línea y tabulador) se guardan y se devuelven tal cual', function (string $observaciones) {
        $e = rn07Escenario([10]);

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e) + ['observaciones' => $observaciones]);

        expect($r->getStatusCode())->toBe(201, $r->getContent())
            ->and(cuerpoDatos($r)['observaciones'] ?? null)->toBe($observaciones)
            ->and(DB::table('traslados')->value('observaciones'))->toBe($observaciones);
    })->with([
        '1000 caracteres multibyte' => str_repeat('ñ', 1000),
        'salto de línea y tabulador' => "Primera línea\n\tsegunda línea con tabulador",
        'retorno de carro' => "Línea uno\r\nLínea dos",
    ]);

    it('RN-07-AC1 S-68: observaciones inválidas → 422 VALIDACION en observaciones y no se crea nada', function (mixed $observaciones) {
        $e = rn07Escenario([10]);
        $antes = rn07Instantanea();

        $r = rn07Crear($e['creador'], rn07CuerpoCrear($e) + ['observaciones' => $observaciones]);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey('observaciones')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        '1001 caracteres' => str_repeat('ñ', 1001),
        'carácter NUL' => "texto\0oculto",
        'espacio de ancho cero U+200B' => "texto\u{200B}oculto",
        'no es texto' => [[1, 2]],
    ]);
});

/** Lista como auditor y devuelve la respuesta. @param array<string, mixed> $consulta */
function rn07Listar(array $consulta = []): TestResponse
{
    Sanctum::actingAs(usuarioConRol('auditor'));

    return test()->getJson(RN07_URI.($consulta === [] ? '' : '?'.http_build_query($consulta)));
}

/** @return list<int> */
function rn07IdsLista(TestResponse $r): array
{
    expect($r->getStatusCode())->toBe(200, $r->getContent());

    return collect($r->json('data'))->pluck('id')->all();
}

describe('RN-07-AC13 S-69 listado', function () {
    it('RN-07-AC13 S-69: el listado va del más reciente al más antiguo y filtra por estado, origen, destino y bodega (origen o destino)', function () {
        $a = rn07Escenario([10]);
        $b = rn07Escenario([10]);
        $primero = rn07Llevar($a, 'SOLICITADO');
        rn07Congelar(0, '11:00:00');
        $segundo = rn07CrearOk($a);
        rn07Congelar(0, '12:00:00');
        // Tercero: de la bodega destino de `a` hacia otra bodega.
        $c = ['origen' => $a['destino'], 'destino' => $b['origen']] + $b;
        $c['lineas'] = [['producto' => $b['lineas'][0]['producto'], 'lote' => $b['lineas'][0]['lote'], 'cantidad' => 1]];
        $tercero = rn07CrearOk($c);

        expect(rn07IdsLista(rn07Listar()))->toBe([$tercero, $segundo, $primero])
            ->and(rn07IdsLista(rn07Listar(['estado' => 'SOLICITADO'])))->toBe([$primero])
            ->and(rn07IdsLista(rn07Listar(['estado' => 'BORRADOR'])))->toBe([$tercero, $segundo])
            ->and(rn07IdsLista(rn07Listar(['bodega_origen_id' => $a['origen']->id])))->toBe([$segundo, $primero])
            ->and(rn07IdsLista(rn07Listar(['bodega_destino_id' => $b['origen']->id])))->toBe([$tercero])
            ->and(rn07IdsLista(rn07Listar(['bodega_id' => $a['destino']->id])))->toBe([$tercero, $segundo, $primero])
            ->and(rn07IdsLista(rn07Listar(['bodega_id' => $a['origen']->id])))->toBe([$segundo, $primero])
            ->and(rn07IdsLista(rn07Listar(['bodega_id' => $a['origen']->id, 'estado' => 'BORRADOR'])))->toBe([$segundo]);
    });

    it('RN-07-AC13 S-69: paginación con por_pagina y page', function () {
        $e = rn07Escenario([10]);
        $ids = [rn07CrearOk($e), rn07CrearOk($e), rn07CrearOk($e)];

        $p1 = rn07Listar(['por_pagina' => 2]);
        $p2 = rn07Listar(['por_pagina' => 2, 'page' => 2]);

        expect(rn07IdsLista($p1))->toBe([$ids[2], $ids[1]])
            ->and($p1->json('meta.per_page'))->toBe(2)
            ->and($p1->json('meta.total'))->toBe(3)
            ->and(rn07IdsLista($p2))->toBe([$ids[0]]);
    });

    it('RN-07-AC13 S-69: filtros mal formados → 422 VALIDACION con el campo', function (string $campo, string $valor) {
        $r = rn07Listar([$campo => $valor]);

        rn07AfirmarValidacion($r);
        expect($r->json('error.detalles.campos'))->toHaveKey($campo);
    })->with([
        'estado desconocido' => ['estado', 'PERDIDO'],
        'estado en minúsculas' => ['estado', 'borrador'],
        'bodega_origen_id no numérico' => ['bodega_origen_id', 'abc'],
        'bodega_destino_id 0' => ['bodega_destino_id', '0'],
        'bodega_id decimal' => ['bodega_id', '1.5'],
        'por_pagina 0' => ['por_pagina', '0'],
        'por_pagina 101' => ['por_pagina', '101'],
        'page 0' => ['page', '0'],
    ]);
});

describe('RN-07-AC6 S-70 recibir 0', function () {
    it('RN-07-AC6 S-70: recibir 0 de un ítem no crea la existencia ni movimiento en destino; solo la discrepancia', function (?int $destinoPrevio) {
        $e = rn07Escenario([10, 4], stocksDestino: [null, $destinoPrevio]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $lote = $e['lineas'][1]['lote'];
        $existenciasAntes = DB::table('existencias')->where('bodega_id', $e['destino']->id)->orderBy('id')->get();

        rn07Hacer($e, $id, 'recibir', cuerpo: rn07CuerpoRecibir($id, [10, 0]))->assertOk();

        expect(rn07StockEn($e['destino'], $lote))->toBe($destinoPrevio)
            ->and(rn07Movs($id)->where('tipo', 'ENTRADA_TRASLADO')->pluck('lote_id')->map(fn ($v): int => (int) $v)->all())
            ->toBe([$e['lineas'][0]['lote']->id])
            ->and(DB::table('movimientos_kardex')->where('bodega_id', $e['destino']->id)->where('lote_id', $lote->id)->where('tipo', '<>', 'ENTRADA')->count())->toBe(0)
            ->and(DB::table('existencias')->where('bodega_id', $e['destino']->id)->count())->toBe($existenciasAntes->count() + 1)
            ->and(rn07Discrepancias($id)->pluck('cantidad')->map(fn ($v): int => (int) $v)->all())->toBe([4]);
    })->with([
        'sin existencia previa en destino' => null,
        'con existencia previa en destino' => 5,
    ]);
});

describe('RN-07 ramas defensivas de la recepción', function () {
    it('RN-07-AC7: RecepcionTraslado::cantidadesPorItem rechaza una recepción con ítems faltantes y ajenos → ValidationException con ambos campos', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $ids = rn07IdsItems($id);
        $items = rn07Modelo($id)->items()->get();

        $ex = rn07Captura(fn () => RecepcionTraslado::cantidadesPorItem($items, [
            new ItemRecibido($ids[0], 10),
            new ItemRecibido(999_999, 1),
        ]));

        expect($ex)->toBeInstanceOf(ValidationException::class)
            ->and($ex->errors())->toHaveKeys(['items', 'items.traslado_item_id'])
            ->and(rn07Estado($id))->toBe('EN_TRANSITO');
    });

    it('RN-07-AC7: RecepcionTraslado::recibir llamado directamente sin un ítem → ValidationException, sigue EN_TRANSITO y nada cambia', function () {
        $e = rn07Escenario([10, 4]);
        $id = rn07Llevar($e, 'EN_TRANSITO');
        $ids = rn07IdsItems($id);
        $antes = rn07Instantanea();

        $ex = rn07Captura(fn () => app(RecepcionTraslado::class)->recibir(rn07Modelo($id), $e['receptor']->id, [new ItemRecibido($ids[0], 10)]));

        expect($ex)->toBeInstanceOf(ValidationException::class)
            ->and($ex->errors())->toHaveKey('items')
            ->and(rn07Instantanea())->toEqual($antes);
    });
});

describe('RN-07 orden cronológico en la BD', function () {
    it('RN-07-AC2: la BD rechaza fechas de acción anteriores a la acción previa (CHECK, 23514)', function (string $estado, array $cambio) {
        $e = rn07Escenario([10]);
        $id = rn07Llevar($e, $estado);
        $fila = rn07Fila($id);
        $antes = rn07Instantanea();

        $valores = array_map(fn (string $columna): string => Carbon::parse($fila->{$columna})->subSecond()->toDateTimeString(), $cambio);
        $ex = rn07Captura(fn () => DB::transaction(fn () => DB::table('traslados')->where('id', $id)->update($valores)));

        expect($ex)->toBeInstanceOf(QueryException::class)
            ->and((string) $ex->getCode())->toBe('23514')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with([
        'solicitado antes de crearse' => ['SOLICITADO', ['solicitado_en' => 'created_at']],
        'aprobado antes de solicitarse' => ['APROBADO', ['aprobado_en' => 'solicitado_en']],
        'despachado antes de aprobarse' => ['EN_TRANSITO', ['despachado_en' => 'aprobado_en']],
        'recibido antes de despacharse' => ['RECIBIDO', ['recibido_en' => 'despachado_en']],
        'anulado antes de crearse' => ['ANULADO', ['anulado_en' => 'created_at']],
    ]);

    it('RN-07-AC2: la BD rechaza anular con fecha anterior a la solicitud o a la aprobación (CHECK, 23514)', function (string $previa) {
        $e = rn07Escenario([10]);
        $id = rn07CrearOk($e);
        rn07Congelar(0, '11:00:00');
        rn07Hacer($e, $id, 'solicitar')->assertOk();
        rn07Congelar(0, '12:00:00');
        rn07Hacer($e, $id, 'aprobar')->assertOk();
        rn07Congelar(1);
        rn07Hacer($e, $id, 'anular')->assertOk();
        $fila = rn07Fila($id);
        $antes = rn07Instantanea();

        // Un segundo antes de `previa`: sigue siendo posterior a la creación (y a la solicitud si
        // `previa` es la aprobación), así solo falla la comparación con `previa`.
        $ex = rn07Captura(fn () => DB::transaction(fn () => DB::table('traslados')->where('id', $id)
            ->update(['anulado_en' => Carbon::parse($fila->{$previa})->subSecond()->toDateTimeString()])));

        expect($ex)->toBeInstanceOf(QueryException::class)
            ->and((string) $ex->getCode())->toBe('23514')
            ->and($ex->getMessage())->toContain('traslados_anulacion_cronologica_check')
            ->and(rn07Instantanea())->toEqual($antes);
    })->with(['solicitado_en', 'aprobado_en']);
});
