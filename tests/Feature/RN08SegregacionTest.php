<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Enums\TipoResolucionDiscrepancia;
use App\Exceptions\Dominio\SegregacionFunciones;
use App\Models\Bodega;
use App\Models\Discrepancia;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\ResolucionDiscrepancia;
use App\Services\Traslados\TrasladoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * RN-08 — Segregación de funciones en traslados: quien solicita un traslado no puede aprobarlo.
 *
 * Supuestos: S-08 (solo el creador solicita → solicitante = creador), S-16 (recibir no exige
 * segregación), S-50 / S-72 (al aprobar: 403 de rol → 403 de segregación → 409), S-71 (la
 * segregación también aplica a resolver discrepancias del traslado), S-73 (al resolver: 403 de rol
 * → 403 de segregación → 409), S-74 (el creador que aprueba su BORRADOR → 409; el solicitante sí
 * despacha), S-75 (detalles del 403 = {traslado_id, accion}, sin datos personales).
 *
 * Actores (como la semilla: regente@ = R1, regente2@ = R2, auxiliar@ = A1), creados con factories.
 * Todos los estados se preparan por el camino real (HTTP); el stock inicial entra por
 * RegistradorKardex (ENTRADA) para que la cadena del kardex cuadre desde 0.
 *
 * Contrato HTTP: POST /traslados {bodega_origen_id, bodega_destino_id, items:[{producto_id, lote_id,
 * cantidad}]}; POST /traslados/{id}/{solicitar|aprobar|despachar|anular {motivo}|recibir
 * {items:[{traslado_item_id, cantidad_recibida}]}}; POST /discrepancias/{id}/resolver {tipo, motivo}.
 */

const RN08_URI = '/api/v1/traslados';
const RN08_URI_DISCREPANCIAS = '/api/v1/discrepancias';
const RN08_HOY = '2026-11-05';
const RN08_MOTIVO_ANULACION = 'Traslado anulado por cambio de plan RN-08';
const RN08_MOTIVO_RESOLUCION = 'Conteo de recepción confirmado RN-08';
const RN08_TIPOS_RESOLUCION = ['PERDIDA', 'REINTEGRO_ORIGEN', 'ENTREGA_DESTINO'];

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn08)
|--------------------------------------------------------------------------
*/

function rn08Congelar(int $dias = 0, string $hora = '10:00:00'): void
{
    test()->travelTo(Carbon::parse(RN08_HOY.' '.$hora, 'America/Bogota')->addDays($dias));
}

/**
 * Actores del escenario: R1, R2 y R3 regentes; A1 y A2 auxiliares.
 *
 * @return array<string, User>
 */
function rn08Actores(): array
{
    return [
        'R1' => usuarioConRol('regente_farmacia', ['email' => 'rn08-regente@farma.test']),
        'R2' => usuarioConRol('regente_farmacia', ['email' => 'rn08-regente2@farma.test']),
        'R3' => usuarioConRol('regente_farmacia', ['email' => 'rn08-regente3@farma.test']),
        'A1' => usuarioConRol('auxiliar_farmacia', ['email' => 'rn08-auxiliar@farma.test']),
        'A2' => usuarioConRol('auxiliar_farmacia', ['email' => 'rn08-auxiliar2@farma.test']),
    ];
}

function rn08Carga(): User
{
    return User::query()->where('email', 'rn08-carga@farma.test')->first()
        ?? usuarioConRol('regente_farmacia', ['email' => 'rn08-carga@farma.test']);
}

/** Existencia (bodega, lote) con `cantidad` cargada por una ENTRADA real. */
function rn08Stock(Bodega $bodega, Lote $lote, int $cantidad): Existencia
{
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        $carga = rn08Carga();
        DB::transaction(function () use ($existencia, $cantidad, $carga): void {
            $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
            app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $carga->id);
        });
    }

    return $existencia->refresh();
}

/**
 * Bodegas origen y destino y un lote vigente por ítem con stock en origen.
 *
 * @param  list<int>  $cantidades
 * @return array{origen: Bodega, destino: Bodega, lineas: list<array{producto: Producto, lote: Lote, cantidad: int, existencia: Existencia}>}
 */
function rn08Escenario(array $cantidades = [10], int $stock = 25): array
{
    $origen = Bodega::factory()->create();
    $destino = Bodega::factory()->create();

    $lineas = [];
    foreach ($cantidades as $cantidad) {
        $producto = Producto::factory()->create();
        $lote = Lote::factory()->create([
            'producto_id' => $producto->id,
            'fecha_vencimiento' => Carbon::parse(RN08_HOY)->addDays(200)->toDateString(),
        ]);
        $lineas[] = ['producto' => $producto, 'lote' => $lote, 'cantidad' => $cantidad, 'existencia' => rn08Stock($origen, $lote, $stock)];
    }

    return ['origen' => $origen, 'destino' => $destino, 'lineas' => $lineas];
}

/** @param array<string, mixed> $e */
function rn08Crear(User $actor, array $e): TestResponse
{
    Sanctum::actingAs($actor);

    return test()->postJson(RN08_URI, [
        'bodega_origen_id' => $e['origen']->id,
        'bodega_destino_id' => $e['destino']->id,
        'items' => array_map(fn (array $l): array => [
            'producto_id' => $l['producto']->id,
            'lote_id' => $l['lote']->id,
            'cantidad' => $l['cantidad'],
        ], $e['lineas']),
    ]);
}

/** @param array<string, mixed> $e */
function rn08CrearOk(User $actor, array $e): int
{
    $r = rn08Crear($actor, $e);
    expect($r->getStatusCode())->toBe(201, 'crear: '.$r->getContent());
    $id = cuerpoDatos($r)['id'] ?? null;
    expect($id)->toBeInt();

    return $id;
}

/** @param array<string, mixed> $cuerpo */
function rn08Accion(User $actor, int $id, string $accion, array $cuerpo = []): TestResponse
{
    Sanctum::actingAs($actor);

    return test()->postJson(RN08_URI."/{$id}/{$accion}", $cuerpo);
}

/** Acción que debe responder 200 (preparación por el camino real). @param array<string, mixed> $cuerpo */
function rn08AccionOk(User $actor, int $id, string $accion, array $cuerpo = []): TestResponse
{
    $r = rn08Accion($actor, $id, $accion, $cuerpo);
    expect($r->getStatusCode())->toBe(200, "preparar: {$accion}: ".$r->getContent());

    return $r;
}

function rn08Items(int $id): Collection
{
    return DB::table('traslado_items')->where('traslado_id', $id)->orderBy('id')->get();
}

/**
 * Cuerpo de recepción con todos los ítems; `recibidas[i]` o, si falta, lo despachado.
 *
 * @param  array<int, int>  $recibidas
 * @return array{items: list<array{traslado_item_id: int, cantidad_recibida: int}>}
 */
function rn08CuerpoRecibir(int $id, array $recibidas = []): array
{
    return ['items' => rn08Items($id)->values()->map(fn (object $it, int $i): array => [
        'traslado_item_id' => (int) $it->id,
        'cantidad_recibida' => $recibidas[$i] ?? (int) $it->cantidad,
    ])->all()];
}

/** Crea con `creador` y solicita con él mismo (S-08). @param array<string, mixed> $e */
function rn08Solicitado(User $creador, array $e): int
{
    $id = rn08CrearOk($creador, $e);
    rn08AccionOk($creador, $id, 'solicitar');
    expect(rn08Estado($id))->toBe('SOLICITADO');

    return $id;
}

/**
 * Lleva un traslado creado y solicitado por `solicitante` hasta `estado` por HTTP.
 *
 * @param  array<string, mixed>  $e
 * @param  array<string, User>  $u  actores
 */
function rn08Llevar(array $e, array $u, User $solicitante, string $estado, ?User $aprobador = null, ?User $despachador = null, ?User $receptor = null): int
{
    $aprobador ??= $u['R2'];
    $despachador ??= $u['A2'];
    $receptor ??= $u['A2'];

    $id = rn08Solicitado($solicitante, $e);
    $pasos = match ($estado) {
        'SOLICITADO' => [],
        'APROBADO' => ['aprobar'],
        'ANULADO_DESDE_SOLICITADO' => ['anular'],
        'ANULADO_DESDE_APROBADO' => ['aprobar', 'anular'],
        'EN_TRANSITO' => ['aprobar', 'despachar'],
        'RECIBIDO' => ['aprobar', 'despachar', 'recibir'],
        'RECIBIDO_PARCIAL' => ['aprobar', 'despachar', 'recibir_parcial'],
    };

    foreach ($pasos as $paso) {
        match ($paso) {
            'aprobar' => rn08AccionOk($aprobador, $id, 'aprobar'),
            'anular' => rn08AccionOk($solicitante, $id, 'anular', ['motivo' => RN08_MOTIVO_ANULACION]),
            'despachar' => rn08AccionOk($despachador, $id, 'despachar'),
            'recibir' => rn08AccionOk($receptor, $id, 'recibir', rn08CuerpoRecibir($id)),
            'recibir_parcial' => rn08AccionOk($receptor, $id, 'recibir', rn08CuerpoRecibir($id, [((int) rn08Items($id)->first()->cantidad) - 3])),
        };
    }

    expect(rn08Estado($id))->toBe(str_starts_with($estado, 'ANULADO') ? 'ANULADO' : $estado);

    return $id;
}

function rn08Fila(int $id): object
{
    $fila = DB::table('traslados')->where('id', $id)->first();
    expect($fila)->not->toBeNull();

    return $fila;
}

function rn08Estado(int $id): string
{
    return (string) DB::table('traslados')->where('id', $id)->value('estado');
}

function rn08StockEn(Bodega $bodega, Lote $lote): ?int
{
    $valor = DB::table('existencias')->where('bodega_id', $bodega->id)->where('lote_id', $lote->id)->value('cantidad');

    return $valor === null ? null : (int) $valor;
}

/** Movimientos de kardex que referencian el traslado (S-35). */
function rn08Movs(int $id): Collection
{
    return DB::table('movimientos_kardex')->where('documento_tipo', 'traslado')->where('documento_id', $id)->orderBy('id')->get();
}

function rn08Discrepancias(int $id): Collection
{
    return DB::table('discrepancias')->whereIn('traslado_item_id', rn08Items($id)->pluck('id'))->orderBy('id')->get();
}

/** @return array<string, list<array<string, mixed>>> */
function rn08Instantanea(): array
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

/** @param array<string, mixed> $cuerpo */
function rn08Resolver(User $actor, int $discrepanciaId, array $cuerpo): TestResponse
{
    Sanctum::actingAs($actor);

    return test()->postJson(RN08_URI_DISCREPANCIAS."/{$discrepanciaId}/resolver", $cuerpo);
}

/**
 * Traslado RECIBIDO_PARCIAL (10 despachadas, 7 recibidas por defecto) solicitado por `solicitante`;
 * devuelve escenario, id del traslado y la discrepancia PENDIENTE.
 *
 * @param  array<string, User>  $u
 * @return array{0: array<string, mixed>, 1: int, 2: object}
 */
function rn08ConDiscrepancia(array $u, User $solicitante, ?User $aprobador = null, ?User $despachador = null, ?User $receptor = null, int $recibido = 7): array
{
    $e = rn08Escenario([10]);
    $id = rn08Llevar($e, $u, $solicitante, 'EN_TRANSITO', $aprobador, $despachador);
    rn08AccionOk($receptor ?? $u['A2'], $id, 'recibir', rn08CuerpoRecibir($id, [$recibido]));
    expect(rn08Estado($id))->toBe('RECIBIDO_PARCIAL');

    $discrepancias = rn08Discrepancias($id);
    expect($discrepancias)->toHaveCount(1)
        ->and($discrepancias->first()->estado)->toBe('PENDIENTE');

    return [$e, $id, $discrepancias->first()];
}

/** S-75: `error.detalles` del 403 de segregación es exactamente {traslado_id, accion}, sin datos personales. */
function rn08EsperarDetallesSegregacion(TestResponse $r, int $trasladoId, string $accion): void
{
    expect($r->json('error.detalles'))->toBe(['traslado_id' => $trasladoId, 'accion' => $accion]);
}

/** Ejecuta la llamada directa al servicio y devuelve la SegregacionFunciones lanzada (o null). */
function rn08CapturarSegregacion(Closure $llamada): ?SegregacionFunciones
{
    try {
        $llamada();
    } catch (SegregacionFunciones $e) {
        return $e;
    }

    return null;
}

/** Afirma clase, código, status y detalles exactos de la SegregacionFunciones del servicio. */
function rn08AfirmarSegregacion(?SegregacionFunciones $lanzada, int $trasladoId, string $accion): void
{
    expect($lanzada)->toBeInstanceOf(SegregacionFunciones::class, 'el servicio debía lanzar SegregacionFunciones')
        ->and($lanzada?->codigo())->toBe('SEGREGACION_FUNCIONES')
        ->and($lanzada?->status())->toBe(403)
        ->and($lanzada?->detalles())->toBe(['traslado_id' => $trasladoId, 'accion' => $accion]);
}

beforeEach(function () {
    rn08Congelar();
});

/*
|--------------------------------------------------------------------------
| AC1 — el solicitante no aprueba
|--------------------------------------------------------------------------
*/

describe('RN-08-AC1 el solicitante no puede aprobar', function () {
    it('RN-08-AC1 RN-08-AC3 S-75: R1 crea y solicita; R1 aprueba → 403 SEGREGACION_FUNCIONES, sigue SOLICITADO sin aprobado_por y nada cambia; luego R2 aprueba → 200 con aprobado_por_id = R2', function (int $intentos) {
        $u = rn08Actores();
        $e = rn08Escenario([10, 4]);
        $id = rn08Solicitado($u['R1'], $e);

        $fila = rn08Fila($id);
        expect((int) $fila->creado_por_id)->toBe($u['R1']->id)
            ->and((int) $fila->solicitado_por_id)->toBe($u['R1']->id);

        $antes = rn08Instantanea();
        for ($i = 0; $i < $intentos; $i++) {
            $r = rn08Accion($u['R1'], $id, 'aprobar');

            esperarError($r, 403, 'SEGREGACION_FUNCIONES');
            rn08EsperarDetallesSegregacion($r, $id, 'aprobar');
            $fila = rn08Fila($id);
            expect($fila->estado)->toBe('SOLICITADO')
                ->and($fila->aprobado_por_id)->toBeNull()
                ->and($fila->aprobado_en)->toBeNull()
                ->and(rn08Instantanea())->toEqual($antes);
        }

        rn08Congelar(0, '11:00:00');
        $r = rn08Accion($u['R2'], $id, 'aprobar');

        expect($r->getStatusCode())->toBe(200, $r->getContent());
        $datos = cuerpoDatos($r);
        expect($datos['estado'] ?? null)->toBe('APROBADO')
            ->and($datos['aprobado_por_id'] ?? null)->toBe($u['R2']->id)
            ->and($datos['solicitado_por_id'] ?? null)->toBe($u['R1']->id);

        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('APROBADO')
            ->and((int) $fila->aprobado_por_id)->toBe($u['R2']->id)
            ->and((int) $fila->solicitado_por_id)->toBe($u['R1']->id)
            ->and(Carbon::parse($fila->aprobado_en)->equalTo(now()))->toBeTrue('aprobado_en = now()');

        // Aprobar no mueve stock ni escribe kardex.
        $despues = rn08Instantanea();
        expect($despues['existencias'])->toEqual($antes['existencias'])
            ->and($despues['kardex'])->toEqual($antes['kardex'])
            ->and(rn08Movs($id))->toHaveCount(0);
    })->with([
        'un intento' => 1,
        'tres intentos seguidos' => 3,
    ]);

    it('RN-08-AC1 S-72 S-75: R1 solicitante intenta aprobar un traslado que ya no está SOLICITADO → 403 SEGREGACION_FUNCIONES (403 antes que 409) y nada cambia', function (string $estado) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Llevar($e, $u, $u['R1'], $estado);
        $filaAntes = rn08Fila($id);
        $antes = rn08Instantanea();

        $r = rn08Accion($u['R1'], $id, 'aprobar');

        esperarError($r, 403, 'SEGREGACION_FUNCIONES');
        rn08EsperarDetallesSegregacion($r, $id, 'aprobar');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe($filaAntes->estado)
            ->and($fila->aprobado_por_id)->toBe($filaAntes->aprobado_por_id)
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'APROBADO (por R2)' => 'APROBADO',
        'ANULADO desde SOLICITADO' => 'ANULADO_DESDE_SOLICITADO',
        'ANULADO desde APROBADO' => 'ANULADO_DESDE_APROBADO',
        'EN_TRANSITO' => 'EN_TRANSITO',
        'RECIBIDO' => 'RECIBIDO',
        'RECIBIDO_PARCIAL' => 'RECIBIDO_PARCIAL',
    ]);

    it('RN-08-AC1 S-72: un regente que no solicitó sobre un traslado ya APROBADO → 409 TRANSICION_INVALIDA y aprobado_por sigue siendo R2', function (string $quien) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Llevar($e, $u, $u['R1'], 'APROBADO');
        $antes = rn08Instantanea();

        $r = rn08Accion($u[$quien], $id, 'aprobar');

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('APROBADO')
            ->and((int) $fila->aprobado_por_id)->toBe($u['R2']->id)
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'tercer regente R3' => 'R3',
        'el mismo aprobador R2 otra vez' => 'R2',
    ]);

    it('RN-08-AC1 S-74: el regente creador de un BORRADOR intenta aprobarlo → 409 TRANSICION_INVALIDA (aún no hay solicitante) y nada cambia', function () {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08CrearOk($u['R1'], $e);
        $antes = rn08Instantanea();

        $r = rn08Accion($u['R1'], $id, 'aprobar');

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('BORRADOR')
            ->and($fila->solicitado_por_id)->toBeNull()
            ->and($fila->aprobado_por_id)->toBeNull()
            ->and($fila->aprobado_en)->toBeNull()
            ->and(rn08Instantanea())->toEqual($antes);
    });

    it('RN-08-AC1 S-72 S-75: TrasladoService::aprobar llamado directamente (sin la Policy) por el solicitante → SegregacionFunciones (403 antes que 409) y nada cambia', function (string $estado) {
        $u = rn08Actores();
        $e = rn08Escenario([10, 4]);
        $id = rn08Llevar($e, $u, $u['R1'], $estado);
        $filaAntes = rn08Fila($id);
        $antes = rn08Instantanea();
        $traslado = Traslado::query()->findOrFail($id);

        $lanzada = rn08CapturarSegregacion(fn () => app(TrasladoService::class)->aprobar($traslado, $u['R1']->id));

        rn08AfirmarSegregacion($lanzada, $id, 'aprobar');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe($filaAntes->estado)
            ->and($fila->aprobado_por_id)->toBe($filaAntes->aprobado_por_id)
            ->and($fila->aprobado_en)->toBe($filaAntes->aprobado_en)
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'SOLICITADO' => 'SOLICITADO',
        'APROBADO (por R2)' => 'APROBADO',
        'ANULADO desde SOLICITADO' => 'ANULADO_DESDE_SOLICITADO',
    ]);
});

/*
|--------------------------------------------------------------------------
| AC2 — solo el creador solicita
|--------------------------------------------------------------------------
*/

describe('RN-08-AC2 nadie distinto del creador solicita', function () {
    it('RN-08-AC2: borrador creado por R1; otro usuario intenta solicitarlo → 403 NO_AUTORIZADO, sigue BORRADOR sin solicitado_por y nada cambia', function (string $intruso) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08CrearOk($u['R1'], $e);
        $actor = $u[$intruso] ?? usuarioConRol($intruso);
        $antes = rn08Instantanea();

        $r = rn08Accion($actor, $id, 'solicitar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('BORRADOR')
            ->and($fila->solicitado_por_id)->toBeNull()
            ->and($fila->solicitado_en)->toBeNull()
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'auxiliar A1' => 'A1',
        'regente R2' => 'R2',
        'regente R3' => 'R3',
        'médico' => 'medico',
        'auditor' => 'auditor',
        'admin' => 'admin',
    ]);

    it('RN-08-AC2: borrador creado por el auxiliar A1; un regente u otro auxiliar intenta solicitarlo → 403 NO_AUTORIZADO y sigue BORRADOR', function (string $intruso) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08CrearOk($u['A1'], $e);
        $antes = rn08Instantanea();

        $r = rn08Accion($u[$intruso], $id, 'solicitar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn08Estado($id))->toBe('BORRADOR')
            ->and(rn08Fila($id)->solicitado_por_id)->toBeNull()
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'regente R1' => 'R1',
        'regente R2' => 'R2',
        'auxiliar A2' => 'A2',
    ]);

    it('RN-08-AC2 RN-08-AC1: R1 no puede eludir la segregación con un tercero que solicite: R2 no solicita (403), R1 solicita y entonces R1 no aprueba (403 SEGREGACION_FUNCIONES) pero R2 sí (200)', function () {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08CrearOk($u['R1'], $e);

        esperarError(rn08Accion($u['R2'], $id, 'solicitar'), 403, 'NO_AUTORIZADO');
        expect(rn08Estado($id))->toBe('BORRADOR');

        rn08AccionOk($u['R1'], $id, 'solicitar');
        expect((int) rn08Fila($id)->solicitado_por_id)->toBe($u['R1']->id);

        esperarError(rn08Accion($u['R1'], $id, 'aprobar'), 403, 'SEGREGACION_FUNCIONES');
        expect(rn08Estado($id))->toBe('SOLICITADO')
            ->and(rn08Fila($id)->aprobado_por_id)->toBeNull();

        rn08AccionOk($u['R2'], $id, 'aprobar');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('APROBADO')
            ->and((int) $fila->aprobado_por_id)->toBe($u['R2']->id)
            ->and((int) $fila->solicitado_por_id)->toBe($u['R1']->id)
            ->and((int) $fila->creado_por_id)->toBe($u['R1']->id);
    });
});

/*
|--------------------------------------------------------------------------
| AC3 — un regente distinto del solicitante aprueba
|--------------------------------------------------------------------------
*/

describe('RN-08-AC3 aprobación por un regente distinto del solicitante', function () {
    it('RN-08-AC3: A1 crea y solicita; un regente que no solicitó lo aprueba → 200 APROBADO con aprobado_por_id = ese regente, sin stock ni kardex', function (string $aprobador) {
        $u = rn08Actores();
        $e = rn08Escenario([10, 4]);
        $id = rn08Solicitado($u['A1'], $e);
        $antes = rn08Instantanea();

        rn08Congelar(0, '12:30:00');
        $r = rn08Accion($u[$aprobador], $id, 'aprobar');

        expect($r->getStatusCode())->toBe(200, $r->getContent());
        $datos = cuerpoDatos($r);
        expect($datos['estado'] ?? null)->toBe('APROBADO')
            ->and($datos['aprobado_por_id'] ?? null)->toBe($u[$aprobador]->id)
            ->and($datos['solicitado_por_id'] ?? null)->toBe($u['A1']->id);

        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('APROBADO')
            ->and((int) $fila->aprobado_por_id)->toBe($u[$aprobador]->id)
            ->and((int) $fila->solicitado_por_id)->toBe($u['A1']->id)
            ->and((int) $fila->creado_por_id)->toBe($u['A1']->id)
            ->and(Carbon::parse($fila->aprobado_en)->equalTo(now()))->toBeTrue('aprobado_en = now()');

        $despues = rn08Instantanea();
        expect($despues['existencias'])->toEqual($antes['existencias'])
            ->and($despues['kardex'])->toEqual($antes['kardex'])
            ->and(rn08Movs($id))->toHaveCount(0);
    })->with([
        'regente R2' => 'R2',
        'regente R1 (no solicitó este traslado)' => 'R1',
    ]);

    it('RN-08-AC3: la segregación es por traslado: R1 aprueba el traslado de R2 y R2 el de R1 → ambos 200', function () {
        $u = rn08Actores();
        $deR1 = rn08Solicitado($u['R1'], rn08Escenario([10]));
        $deR2 = rn08Solicitado($u['R2'], rn08Escenario([5]));

        rn08AccionOk($u['R1'], $deR2, 'aprobar');
        rn08AccionOk($u['R2'], $deR1, 'aprobar');

        expect((int) rn08Fila($deR2)->aprobado_por_id)->toBe($u['R1']->id)
            ->and((int) rn08Fila($deR1)->aprobado_por_id)->toBe($u['R2']->id)
            ->and(rn08Estado($deR1))->toBe('APROBADO')
            ->and(rn08Estado($deR2))->toBe('APROBADO');
    });
});

/*
|--------------------------------------------------------------------------
| AC4 — un auxiliar no aprueba (rol antes que segregación)
|--------------------------------------------------------------------------
*/

describe('RN-08-AC4 un auxiliar no aprueba', function () {
    it('RN-08-AC4 S-50 S-72: un auxiliar intenta aprobar → 403 NO_AUTORIZADO (el rol se evalúa antes que la segregación) y sigue SOLICITADO', function (string $solicitante, string $actor) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Solicitado($u[$solicitante], $e);
        $antes = rn08Instantanea();

        $r = rn08Accion($u[$actor], $id, 'aprobar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('SOLICITADO')
            ->and($fila->aprobado_por_id)->toBeNull()
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'A1 aprueba su propio traslado' => ['A1', 'A1'],
        'A2 aprueba el traslado de A1' => ['A1', 'A2'],
        'A1 aprueba el traslado de R1' => ['R1', 'A1'],
    ]);

    it('RN-08-AC4: médico, auditor o admin intentan aprobar → 403 NO_AUTORIZADO y sigue SOLICITADO', function (string $rol) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Solicitado($u['A1'], $e);
        $antes = rn08Instantanea();

        $r = rn08Accion(usuarioConRol($rol), $id, 'aprobar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn08Estado($id))->toBe('SOLICITADO')
            ->and(rn08Instantanea())->toEqual($antes);
    })->with(['medico', 'auditor', 'admin']);

    it('RN-08-AC4 S-50 S-72: el auxiliar solicitante sobre su traslado ya APROBADO → 403 NO_AUTORIZADO (rol antes que segregación y que 409)', function () {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Llevar($e, $u, $u['A1'], 'APROBADO');
        $antes = rn08Instantanea();

        $r = rn08Accion($u['A1'], $id, 'aprobar');

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn08Estado($id))->toBe('APROBADO')
            ->and((int) rn08Fila($id)->aprobado_por_id)->toBe($u['R2']->id)
            ->and(rn08Instantanea())->toEqual($antes);
    });

    it('RN-08-AC4: aprobar sin token → 401 NO_AUTENTICADO y sigue SOLICITADO', function () {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $id = rn08Solicitado($u['R1'], $e);
        $antes = rn08Instantanea();
        olvidarAutenticacion();

        $r = test()->postJson(RN08_URI."/{$id}/aprobar");

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn08Estado($id))->toBe('SOLICITADO')
            ->and(rn08Instantanea())->toEqual($antes);
    });
});

/*
|--------------------------------------------------------------------------
| AC5 — CHECK en la BD
|--------------------------------------------------------------------------
*/

/**
 * Ejecuta en un savepoint el SQL directo del caso y devuelve el SQLSTATE (null si se aceptó).
 * `$aprobador` es quien queda como aprobado_por_id; con `igual` = true se fuerza
 * aprobado_por_id = solicitado_por_id, el resto de columnas cumple todos los demás CHECK.
 *
 * @param  array<string, mixed>  $ctx
 */
function rn08SqlDirecto(string $caso, array $ctx, bool $igual): ?string
{
    $u = $ctx['u'];
    $ahora = now();

    try {
        DB::transaction(function () use ($caso, $ctx, $u, $igual, $ahora): void {
            match ($caso) {
                'UPDATE SOLICITADO → APROBADO por el solicitante' => DB::table('traslados')->where('id', $ctx['solicitado'])->update([
                    'estado' => 'APROBADO',
                    'aprobado_por_id' => $igual ? $u['R1']->id : $u['R2']->id,
                    'aprobado_en' => $ahora,
                ]),
                'UPDATE aprobado_por_id de un APROBADO al solicitante' => DB::table('traslados')->where('id', $ctx['aprobado'])->update([
                    'aprobado_por_id' => $igual ? $u['R1']->id : $u['R3']->id,
                ]),
                'UPDATE creador y solicitante de un APROBADO al aprobador' => DB::table('traslados')->where('id', $ctx['aprobado'])->update([
                    'creado_por_id' => $igual ? $u['R2']->id : $u['R3']->id,
                    'solicitado_por_id' => $igual ? $u['R2']->id : $u['R3']->id,
                ]),
                'INSERT APROBADO con aprobador = solicitante' => DB::table('traslados')->insert([
                    'bodega_origen_id' => $ctx['e']['origen']->id,
                    'bodega_destino_id' => $ctx['e']['destino']->id,
                    'estado' => 'APROBADO',
                    'creado_por_id' => $u['R1']->id,
                    'solicitado_por_id' => $u['R1']->id,
                    'solicitado_en' => $ahora,
                    'aprobado_por_id' => $igual ? $u['R1']->id : $u['R2']->id,
                    'aprobado_en' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]),
                'INSERT EN_TRANSITO con aprobador = solicitante' => DB::table('traslados')->insert([
                    'bodega_origen_id' => $ctx['e']['origen']->id,
                    'bodega_destino_id' => $ctx['e']['destino']->id,
                    'estado' => 'EN_TRANSITO',
                    'creado_por_id' => $u['A1']->id,
                    'solicitado_por_id' => $u['A1']->id,
                    'solicitado_en' => $ahora,
                    'aprobado_por_id' => $igual ? $u['A1']->id : $u['R2']->id,
                    'aprobado_en' => $ahora,
                    'despachado_por_id' => $u['A2']->id,
                    'despachado_en' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]),
            };
        });
    } catch (QueryException $ex) {
        return (string) $ex->getCode();
    }

    return null;
}

/** @return array<string, mixed> */
function rn08ContextoSql(): array
{
    $u = rn08Actores();
    $e = rn08Escenario([10]);
    $solicitado = rn08Solicitado($u['R1'], $e);
    $aprobado = rn08Llevar($e, $u, $u['R1'], 'APROBADO');

    return ['u' => $u, 'e' => $e, 'solicitado' => $solicitado, 'aprobado' => $aprobado];
}

dataset('rn08_sql_directo', [
    'UPDATE SOLICITADO → APROBADO por el solicitante',
    'UPDATE aprobado_por_id de un APROBADO al solicitante',
    'UPDATE creador y solicitante de un APROBADO al aprobador',
    'INSERT APROBADO con aprobador = solicitante',
    'INSERT EN_TRANSITO con aprobador = solicitante',
]);

describe('RN-08-AC5 CHECK aprobado_por_id <> solicitado_por_id', function () {
    it('RN-08-AC5: SQL directo con aprobado_por_id = solicitado_por_id → la BD lo rechaza (23514) y las filas quedan intactas', function (string $caso) {
        $ctx = rn08ContextoSql();
        $antes = rn08Instantanea();

        $sqlstate = rn08SqlDirecto($caso, $ctx, igual: true);

        expect($sqlstate)->toBe('23514', "{$caso}: la BD debía rechazarlo por CHECK")
            ->and(rn08Instantanea())->toEqual($antes);

        $solicitado = rn08Fila($ctx['solicitado']);
        $aprobado = rn08Fila($ctx['aprobado']);
        expect($solicitado->estado)->toBe('SOLICITADO')
            ->and($solicitado->aprobado_por_id)->toBeNull()
            ->and($aprobado->estado)->toBe('APROBADO')
            ->and((int) $aprobado->aprobado_por_id)->toBe($ctx['u']['R2']->id)
            ->and((int) $aprobado->solicitado_por_id)->toBe($ctx['u']['R1']->id);
    })->with('rn08_sql_directo');

    it('RN-08-AC5: control — el mismo SQL directo con un aprobador distinto del solicitante se acepta (solo la segregación lo rechazaba)', function (string $caso) {
        $ctx = rn08ContextoSql();

        $sqlstate = rn08SqlDirecto($caso, $ctx, igual: false);

        expect($sqlstate)->toBeNull("{$caso}: con aprobador distinto no debía violar ningún CHECK");
    })->with('rn08_sql_directo');

    it('RN-08-AC5: la tabla traslados declara un CHECK que compara aprobado_por_id con solicitado_por_id', function () {
        $definiciones = collect(DB::select(
            "SELECT pg_get_constraintdef(c.oid) AS def FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relname = 'traslados' AND c.contype = 'c'"
        ))->pluck('def');

        $segregacion = $definiciones->filter(fn (string $def): bool => (bool) preg_match(
            '/aprobado_por_id\s*<>\s*solicitado_por_id|solicitado_por_id\s*<>\s*aprobado_por_id/',
            $def,
        ));

        expect($segregacion)->not->toBeEmpty('falta CHECK (aprobado_por_id IS NULL OR aprobado_por_id <> solicitado_por_id)');
    });
});

/*
|--------------------------------------------------------------------------
| S-71 — la segregación también aplica a resolver discrepancias
|--------------------------------------------------------------------------
*/

describe('RN-08 S-71 segregación al resolver discrepancias', function () {
    it('RN-08-AC1 S-71 S-74 S-75: R1 solicitó (R2 aprobó); R1 intenta resolver la discrepancia → 403 SEGREGACION_FUNCIONES, sigue PENDIENTE sin ajuste ni cambios; luego R2 resuelve → 200 RESUELTA', function (string $tipo, string $operador) {
        $u = rn08Actores();
        // El solicitante puede además haber despachado (S-74) y recibido (S-16): sigue sin poder resolver.
        [$e, $id, $d] = rn08ConDiscrepancia($u, $u['R1'], $u['R2'], $u[$operador], $u[$operador]);
        $l = $e['lineas'][0];
        $origenAntes = rn08StockEn($e['origen'], $l['lote']);
        $destinoAntes = rn08StockEn($e['destino'], $l['lote']) ?? 0;
        $antes = rn08Instantanea();

        $r = rn08Resolver($u['R1'], (int) $d->id, ['tipo' => $tipo, 'motivo' => RN08_MOTIVO_RESOLUCION]);

        esperarError($r, 403, 'SEGREGACION_FUNCIONES');
        rn08EsperarDetallesSegregacion($r, $id, 'resolver_discrepancia');
        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        expect($fila->estado)->toBe('PENDIENTE')
            ->and($fila->resuelto_por_id)->toBeNull()
            ->and($fila->tipo_resolucion)->toBeNull()
            ->and(rn08Estado($id))->toBe('RECIBIDO_PARCIAL')
            ->and(rn08Instantanea())->toEqual($antes);

        rn08Congelar(1, '09:00:00');
        $r = rn08Resolver($u['R2'], (int) $d->id, ['tipo' => $tipo, 'motivo' => RN08_MOTIVO_RESOLUCION]);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('RESUELTA')
            ->and(cuerpoDatos($r)['resuelto_por_id'] ?? null)->toBe($u['R2']->id);
        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        expect($fila->estado)->toBe('RESUELTA')
            ->and($fila->tipo_resolucion)->toBe($tipo)
            ->and((int) $fila->resuelto_por_id)->toBe($u['R2']->id);

        $nuevos = DB::table('movimientos_kardex')->whereNotIn('id', array_column($antes['kardex'], 'id'))->get();
        if ($tipo === 'PERDIDA') {
            expect($nuevos)->toHaveCount(0)
                ->and(rn08StockEn($e['origen'], $l['lote']))->toBe($origenAntes)
                ->and(rn08StockEn($e['destino'], $l['lote']) ?? 0)->toBe($destinoAntes);

            return;
        }

        expect($nuevos)->toHaveCount(1)
            ->and($nuevos->first()->tipo)->toBe('AJUSTE')
            ->and((int) $nuevos->first()->cantidad)->toBe(3)
            ->and((int) $nuevos->first()->usuario_id)->toBe($u['R2']->id);
        if ($tipo === 'REINTEGRO_ORIGEN') {
            expect(rn08StockEn($e['origen'], $l['lote']))->toBe($origenAntes + 3)
                ->and(rn08StockEn($e['destino'], $l['lote']) ?? 0)->toBe($destinoAntes);
        } else {
            expect(rn08StockEn($e['destino'], $l['lote']))->toBe($destinoAntes + 3)
                ->and(rn08StockEn($e['origen'], $l['lote']))->toBe($origenAntes);
        }
    })->with(RN08_TIPOS_RESOLUCION)->with([
        'despacha y recibe un auxiliar' => 'A2',
        'el propio R1 despacha y recibe' => 'R1',
    ]);

    it('RN-08-AC1 S-71: traslado de R1 con varias discrepancias → R1 no resuelve ninguna (403 SEGREGACION_FUNCIONES) y todas siguen PENDIENTE', function () {
        $u = rn08Actores();
        $e = rn08Escenario([10, 4]);
        $id = rn08Llevar($e, $u, $u['R1'], 'EN_TRANSITO');
        rn08AccionOk($u['A2'], $id, 'recibir', rn08CuerpoRecibir($id, [8, 0]));
        $discrepancias = rn08Discrepancias($id);
        expect($discrepancias)->toHaveCount(2);
        $antes = rn08Instantanea();

        foreach ($discrepancias as $d) {
            esperarError(rn08Resolver($u['R1'], (int) $d->id, ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => RN08_MOTIVO_RESOLUCION]), 403, 'SEGREGACION_FUNCIONES');
        }

        expect(rn08Discrepancias($id)->pluck('estado')->unique()->values()->all())->toBe(['PENDIENTE'])
            ->and(rn08Instantanea())->toEqual($antes);
    });

    it('RN-08-AC3 S-71: si el solicitante fue un auxiliar, cualquier regente resuelve → 200 RESUELTA con resuelto_por_id = ese regente', function (string $resolutor, string $tipo) {
        $u = rn08Actores();
        // R2 aprueba; R3 despacha y recibe: así se cubre al aprobador, a un regente ajeno y al operador.
        [$e, $id, $d] = rn08ConDiscrepancia($u, $u['A1'], $u['R2'], $u['R3'], $u['R3']);

        $r = rn08Resolver($u[$resolutor], (int) $d->id, ['tipo' => $tipo, 'motivo' => RN08_MOTIVO_RESOLUCION]);

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('RESUELTA');
        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        expect($fila->estado)->toBe('RESUELTA')
            ->and($fila->tipo_resolucion)->toBe($tipo)
            ->and((int) $fila->resuelto_por_id)->toBe($u[$resolutor]->id);
    })->with([
        'R1 (no intervino)' => 'R1',
        'R2 (el aprobador)' => 'R2',
        'R3 (despachó y recibió)' => 'R3',
    ])->with(['PERDIDA', 'REINTEGRO_ORIGEN']);

    it('RN-08-AC4 S-71 S-73: un auxiliar (incluido el solicitante) intenta resolver → 403 NO_AUTORIZADO (rol antes que segregación) y sigue PENDIENTE', function (string $solicitante, string $actor) {
        $u = rn08Actores();
        [$e, $id, $d] = rn08ConDiscrepancia($u, $u[$solicitante]);
        $antes = rn08Instantanea();

        $r = rn08Resolver($u[$actor], (int) $d->id, ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => RN08_MOTIVO_RESOLUCION]);

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(DB::table('discrepancias')->where('id', $d->id)->value('estado'))->toBe('PENDIENTE')
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'A1 solicitante resuelve la suya' => ['A1', 'A1'],
        'A2 (despachó y recibió) resuelve la de A1' => ['A1', 'A2'],
        'A1 resuelve la de un traslado de R1' => ['R1', 'A1'],
    ]);

    it('RN-08-AC1 S-71 S-73 S-75: R1 solicitante sobre una discrepancia ya RESUELTA → 403 SEGREGACION_FUNCIONES (403 antes que 409) y nada cambia', function () {
        $u = rn08Actores();
        [$e, $id, $d] = rn08ConDiscrepancia($u, $u['R1']);
        rn08Resolver($u['R2'], (int) $d->id, ['tipo' => 'PERDIDA', 'motivo' => RN08_MOTIVO_RESOLUCION])->assertOk();
        $antes = rn08Instantanea();

        $r = rn08Resolver($u['R1'], (int) $d->id, ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => RN08_MOTIVO_RESOLUCION]);

        esperarError($r, 403, 'SEGREGACION_FUNCIONES');
        rn08EsperarDetallesSegregacion($r, $id, 'resolver_discrepancia');
        expect(rn08Instantanea())->toEqual($antes);
    });
});

describe('RN-08 S-71 segregación al resolver discrepancias: defensa en el servicio', function () {
    it('RN-08-AC1 S-71 S-73 S-75: ResolucionDiscrepancia::resolver llamado directamente por el solicitante → SegregacionFunciones (403 antes que 409), sin kardex ni cambios de stock', function (string $estado, string $tipo) {
        $u = rn08Actores();
        [$e, $id, $d] = rn08ConDiscrepancia($u, $u['R1']);
        if ($estado === 'RESUELTA') {
            rn08Resolver($u['R2'], (int) $d->id, ['tipo' => 'PERDIDA', 'motivo' => RN08_MOTIVO_RESOLUCION])->assertOk();
        }
        $filaAntes = DB::table('discrepancias')->where('id', $d->id)->first();
        expect($filaAntes->estado)->toBe($estado);
        $antes = rn08Instantanea();
        $discrepancia = Discrepancia::query()->findOrFail($d->id);

        $lanzada = rn08CapturarSegregacion(fn () => app(ResolucionDiscrepancia::class)->resolver(
            $discrepancia,
            $u['R1']->id,
            TipoResolucionDiscrepancia::from($tipo),
            RN08_MOTIVO_RESOLUCION,
        ));

        rn08AfirmarSegregacion($lanzada, $id, 'resolver_discrepancia');
        $fila = DB::table('discrepancias')->where('id', $d->id)->first();
        expect((array) $fila)->toEqual((array) $filaAntes)
            ->and(rn08Estado($id))->toBe('RECIBIDO_PARCIAL')
            ->and(rn08Instantanea())->toEqual($antes);
    })->with([
        'PENDIENTE' => 'PENDIENTE',
        'ya RESUELTA por R2' => 'RESUELTA',
    ])->with(RN08_TIPOS_RESOLUCION);
});

/*
|--------------------------------------------------------------------------
| S-16 — recibir no exige segregación
|--------------------------------------------------------------------------
*/

describe('RN-08 S-16 la recepción no exige segregación', function () {
    it('RN-08-AC1 S-16 S-74: quien despacha también puede recibir → 200 RECIBIDO, origen −10, destino +10 y SALIDA/ENTRADA a nombre de ese usuario', function (string $operador) {
        $u = rn08Actores();
        $e = rn08Escenario([10]);
        $l = $e['lineas'][0];
        $id = rn08Llevar($e, $u, $u['R1'], 'APROBADO');

        rn08AccionOk($u[$operador], $id, 'despachar');
        $r = rn08Accion($u[$operador], $id, 'recibir', rn08CuerpoRecibir($id));

        expect($r->getStatusCode())->toBe(200, $r->getContent())
            ->and(cuerpoDatos($r)['estado'] ?? null)->toBe('RECIBIDO');
        $fila = rn08Fila($id);
        expect($fila->estado)->toBe('RECIBIDO')
            ->and((int) $fila->despachado_por_id)->toBe($u[$operador]->id)
            ->and((int) $fila->recibido_por_id)->toBe($u[$operador]->id)
            ->and((int) $fila->solicitado_por_id)->toBe($u['R1']->id)
            ->and((int) $fila->aprobado_por_id)->toBe($u['R2']->id)
            ->and(rn08StockEn($e['origen'], $l['lote']))->toBe(15)
            ->and(rn08StockEn($e['destino'], $l['lote']))->toBe(10)
            ->and(rn08Discrepancias($id))->toHaveCount(0);

        $movs = rn08Movs($id);
        expect($movs)->toHaveCount(2)
            ->and($movs->pluck('tipo')->all())->toBe(['SALIDA_TRASLADO', 'ENTRADA_TRASLADO'])
            ->and($movs->pluck('usuario_id')->map(fn ($v): int => (int) $v)->unique()->values()->all())->toBe([$u[$operador]->id]);
    })->with([
        'un auxiliar ajeno' => 'A2',
        'R1, el solicitante' => 'R1',
        'R2, el aprobador' => 'R2',
    ]);
});
