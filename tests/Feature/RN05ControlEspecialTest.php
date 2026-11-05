<?php

declare(strict_types=1);

use App\Enums\TipoMovimiento;
use App\Exceptions\Dominio\AutorizacionMismoUsuario;
use App\Models\Bodega;
use App\Models\Dispensacion;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\User;
use App\Services\Dispensacion\AutorizacionDispensacion;
use App\Services\Inventario\RegistradorKardex;
use Database\Seeders\UsuariosSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * RN-05 — Control especial: autorización de un segundo regente (AC1..AC9). AC10 (autorizaciones
 * simultáneas) va en tests/Feature/SinTransaccion/RN05AutorizacionConcurrenteTest.php.
 *
 * Supuestos: S-01 (un lote que vence hoy ya está vencido), S-03 (empate FEFO → menor lotes.id),
 * S-06 (dos pasos: crear pendiente → autorizar; el stock se descuenta al autorizar, no se reserva;
 * mixta → pendiente entera; un regente que dispensa también necesita a otro regente), S-13 (admin
 * no opera inventario ni dispensaciones), S-19 (semilla con regente@ y regente2@), S-42 (detalles
 * de STOCK_INSUFICIENTE), S-48, S-50 (401 → 403 → 422), S-52 (el reintento reproduce el original
 * aunque la dispensación cambie después), S-54, S-56, S-58 (salidas a nombre del autorizador),
 * S-61 (crear una pendiente comprueba el stock vigente sin reservarlo).
 *
 * Contrato que se prueba:
 *  - POST /api/v1/dispensaciones con algún ítem control_especial → 201 PENDIENTE_AUTORIZACION, sin
 *    detalles, sin kardex y sin tocar existencias; los ítems quedan guardados y cuentan para el tope.
 *  - POST /api/v1/dispensaciones/{id}/autorizar (regente ≠ creador) → 200 CONFIRMADA con
 *    autorizado_por_id y autorizado_en (= instante de la autorización); salida FEFO evaluada en ese
 *    instante, con SALIDA_DISPENSACION documento 'dispensacion' + id.
 *  - POST /api/v1/dispensaciones/{id}/rechazar {motivo} (regente ≠ creador) → 200 RECHAZADA con
 *    rechazado_por_id, rechazado_en y motivo_rechazo; sin movimientos.
 *  - Errores: 403 NO_AUTORIZADO (rol), 403 AUTORIZACION_MISMO_USUARIO, 409 STOCK_INSUFICIENTE (sigue
 *    pendiente), 409 TRANSICION_INVALIDA, 422 VALIDACION (motivo), 404 NO_ENCONTRADO, 401.
 */

const RN05_URI = '/api/v1/dispensaciones';
const RN05_URI_AJUSTES = '/api/v1/inventario/ajustes';
const RN05_HOY = '2026-11-04';
const RN05_MOTIVO = 'Soporte de control especial incompleto';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn05)
|--------------------------------------------------------------------------
*/

/** Congela el reloj en una hora local de Bogotá (por defecto 2026-11-04 10:00). */
function rn05Congelar(string $fechaHoraBogota = RN05_HOY.' 10:00:00'): void
{
    test()->travelTo(Carbon::parse($fechaHoraBogota, 'America/Bogota'));
}

/** Fecha Y-m-d a N días de RN05_HOY (fija, no depende del reloj actual). */
function rn05Dia(int $dias): string
{
    return Carbon::parse(RN05_HOY, 'America/Bogota')->addDays($dias)->toDateString();
}

/** Usuario con rol, identificado por un alias estable dentro del test. */
function rn05Usuario(string $rol, string $alias): User
{
    $email = "rn05-{$alias}@farma.test";

    return User::query()->where('email', $email)->first()
        ?? usuarioConRol($rol, ['email' => $email]);
}

function rn05Auxiliar(string $alias = 'auxiliar'): User
{
    return rn05Usuario('auxiliar_farmacia', $alias);
}

function rn05Regente(string $alias): User
{
    return rn05Usuario('regente_farmacia', "regente-{$alias}");
}

/** ENTRADA real (kardex encadenado) de `cantidad` sobre la existencia, por el regente de carga. */
function rn05Entrada(Existencia $existencia, int $cantidad): void
{
    $regente = rn05Regente('carga');
    DB::transaction(function () use ($existencia, $cantidad, $regente): void {
        $bloqueada = Existencia::query()->whereKey($existencia->id)->lockForUpdate()->firstOrFail();
        app(RegistradorKardex::class)->registrar(TipoMovimiento::Entrada, $bloqueada, $cantidad, $regente->id);
    });
}

/** Lote nuevo del producto con `cantidad` en la bodega, que vence a `venceEnDias` de RN05_HOY. */
function rn05Lote(Bodega $bodega, Producto $producto, int $cantidad, int $venceEnDias = 200): Existencia
{
    $lote = Lote::factory()->create(['producto_id' => $producto->id, 'fecha_vencimiento' => rn05Dia($venceEnDias)]);
    $existencia = Existencia::factory()->paraLote($lote)->create(['bodega_id' => $bodega->id, 'cantidad' => 0]);

    if ($cantidad > 0) {
        rn05Entrada($existencia, $cantidad);
    }

    return $existencia->refresh();
}

/**
 * Escenario: una bodega y una prescripción vigente con una línea por elemento de `$lineas`
 * (producto propio, con o sin control especial, su ítem prescrito y sus lotes en la bodega).
 *
 * @param  list<array{control: bool, prescrito?: int, lotes?: list<array{0: int, 1?: int}>}>  $lineas
 * @return array{bodega: Bodega, prescripcion: Prescripcion, lineas: list<array{producto: Producto, item: PrescripcionItem, existencias: list<Existencia>}>}
 */
function rn05Escenario(array $lineas): array
{
    $bodega = Bodega::factory()->create();
    $prescripcion = Prescripcion::factory()->create();
    $resultado = [];

    foreach ($lineas as $linea) {
        $producto = $linea['control']
            ? Producto::factory()->controlEspecial()->create()
            : Producto::factory()->create();

        $existencias = [];
        foreach ($linea['lotes'] ?? [[20]] as $lote) {
            $existencias[] = rn05Lote($bodega, $producto, $lote[0], $lote[1] ?? 200);
        }

        $item = PrescripcionItem::factory()->create([
            'prescripcion_id' => $prescripcion->id,
            'producto_id' => $producto->id,
            'cantidad' => $linea['prescrito'] ?? 10,
        ]);

        $resultado[] = compact('producto', 'item', 'existencias');
    }

    return ['bodega' => $bodega, 'prescripcion' => $prescripcion, 'lineas' => $resultado];
}

/**
 * Composiciones de escenario por nombre (las cantidades de rn05Composicion() van en el mismo orden).
 *
 * @return list<array{control: bool, prescrito?: int, lotes?: list<array{0: int, 1?: int}>}>
 */
function rn05Lineas(string $composicion): array
{
    return match ($composicion) {
        'solo control' => [['control' => true, 'prescrito' => 10, 'lotes' => [[20]]]],
        'control + sin control' => [
            ['control' => true, 'prescrito' => 10, 'lotes' => [[20]]],
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
        ],
        'sin control + control' => [
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
            ['control' => true, 'prescrito' => 10, 'lotes' => [[20]]],
        ],
        'sin control, sin control, control' => [
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
            ['control' => true, 'prescrito' => 10, 'lotes' => [[20]]],
        ],
        'un ítem sin control' => [['control' => false, 'prescrito' => 10, 'lotes' => [[20]]]],
        'dos ítems sin control' => [
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
            ['control' => false, 'prescrito' => 10, 'lotes' => [[20]]],
        ],
        default => throw new InvalidArgumentException("composición desconocida: {$composicion}"),
    };
}

/** @return list<int> cantidades a pedir por línea para cada composición */
function rn05Composicion(string $composicion): array
{
    return match ($composicion) {
        'solo control', 'un ítem sin control' => [4],
        'control + sin control', 'sin control + control', 'dos ítems sin control' => [4, 3],
        'sin control, sin control, control' => [2, 3, 4],
        default => throw new InvalidArgumentException("composición desconocida: {$composicion}"),
    };
}

/**
 * Cuerpo de la dispensación: cantidades por línea (null = esa línea no se pide).
 *
 * @param  array<string, mixed>  $s
 * @param  list<int|null>  $cantidades
 * @return array<string, mixed>
 */
function rn05Cuerpo(array $s, array $cantidades): array
{
    $items = [];
    foreach ($cantidades as $i => $cantidad) {
        if ($cantidad !== null) {
            $items[] = ['prescripcion_item_id' => $s['lineas'][$i]['item']->id, 'cantidad' => $cantidad];
        }
    }

    return [
        'paciente_id' => $s['prescripcion']->paciente_id,
        'prescripcion_id' => $s['prescripcion']->id,
        'bodega_id' => $s['bodega']->id,
        'items' => $items,
    ];
}

/**
 * POST /dispensaciones como `$como` (clave nueva salvo que se indique).
 *
 * @param  array<string, mixed>  $s
 * @param  list<int|null>  $cantidades
 */
function rn05Crear(User $como, array $s, array $cantidades, ?string $clave = null): TestResponse
{
    Sanctum::actingAs($como);

    return test()->postJson(
        RN05_URI,
        rn05Cuerpo($s, $cantidades),
        $clave === null ? cabecerasDispensacion() : ['Idempotency-Key' => $clave],
    );
}

function rn05Autorizar(int|string $id, ?User $como = null): TestResponse
{
    if ($como !== null) {
        Sanctum::actingAs($como);
    }

    return test()->postJson(RN05_URI."/{$id}/autorizar");
}

/** @param array<string, mixed> $cuerpo */
function rn05Rechazar(int|string $id, ?User $como = null, array $cuerpo = ['motivo' => RN05_MOTIVO]): TestResponse
{
    if ($como !== null) {
        Sanctum::actingAs($como);
    }

    return test()->postJson(RN05_URI."/{$id}/rechazar", $cuerpo);
}

/** Ejecuta la acción indicada ('autorizar' | 'rechazar') con el cuerpo válido. */
function rn05Accion(string $accion, int|string $id, ?User $como = null): TestResponse
{
    return match ($accion) {
        'autorizar' => rn05Autorizar($id, $como),
        'rechazar' => rn05Rechazar($id, $como),
        default => throw new InvalidArgumentException("acción desconocida: {$accion}"),
    };
}

function rn05StockDe(Existencia $existencia): int
{
    return (int) DB::table('existencias')->where('id', $existencia->id)->value('cantidad');
}

/**
 * Stock actual de todas las existencias del escenario: existencia_id → cantidad.
 *
 * @param  array<string, mixed>  $s
 * @return array<int, int>
 */
function rn05Stocks(array $s): array
{
    $stocks = [];
    foreach ($s['lineas'] as $linea) {
        foreach ($linea['existencias'] as $existencia) {
            $stocks[$existencia->id] = rn05StockDe($existencia);
        }
    }

    return $stocks;
}

/**
 * Estado completo de las tablas que una dispensación puede tocar.
 *
 * @return array<string, mixed>
 */
function rn05Instantanea(): array
{
    $filas = fn (string $tabla): array => DB::table($tabla)->orderBy('id')->get()
        ->map(fn (object $f): array => (array) $f)->all();

    return [
        'existencias' => $filas('existencias'),
        'kardex' => $filas('movimientos_kardex'),
        'dispensaciones' => $filas('dispensaciones'),
        'dispensacion_items' => $filas('dispensacion_items'),
        'dispensacion_detalles' => $filas('dispensacion_detalles'),
    ];
}

function rn05Estado(int $id): ?string
{
    $estado = DB::table('dispensaciones')->where('id', $id)->value('estado');

    return $estado === null ? null : (string) $estado;
}

/** Movimientos de kardex cuyo documento es la dispensación. */
function rn05MovimientosDe(int $id): Collection
{
    return DB::table('movimientos_kardex')
        ->where('documento_tipo', 'dispensacion')
        ->where('documento_id', $id)
        ->orderBy('id')
        ->get();
}

/** Cadena de saldos de la existencia desde 0 hasta su cantidad actual (RN-06-AC2). */
function rn05AfirmarCadena(Existencia $existencia): void
{
    $saldo = 0;
    foreach (DB::table('movimientos_kardex')->where('existencia_id', $existencia->id)->orderBy('id')->get() as $i => $m) {
        expect((int) $m->saldo_anterior)->toBe($saldo, "existencia {$existencia->id}, movimiento #{$i}: saldo_anterior no encadena")
            ->and((int) $m->saldo_posterior)->toBe((int) $m->saldo_anterior + (int) $m->cantidad)
            ->and((int) $m->saldo_posterior)->toBeGreaterThanOrEqual(0);
        $saldo = (int) $m->saldo_posterior;
    }
    expect($saldo)->toBe(rn05StockDe($existencia), "existencia {$existencia->id}: el kardex no cuadra con el stock");
}

/**
 * 201 PENDIENTE_AUTORIZACION: ítems guardados, sin detalles, sin kardex y sin autorización.
 *
 * @param  array<string, mixed>  $s
 * @param  list<int|null>  $cantidades
 */
function rn05AfirmarPendiente(TestResponse $r, array $s, array $cantidades, User $creador): int
{
    $r->assertCreated();
    $d = cuerpoDatos($r);
    $id = $d['id'] ?? null;
    expect($id)->toBeInt()
        ->and($d['estado'] ?? null)->toBe('PENDIENTE_AUTORIZACION')
        ->and($d['dispensado_por_id'] ?? null)->toBe($creador->id)
        ->and(array_key_exists('autorizado_por_id', $d) ? $d['autorizado_por_id'] : 'falta')->toBeNull()
        ->and(array_key_exists('autorizado_en', $d) ? $d['autorizado_en'] : 'falta')->toBeNull()
        ->and($d['detalles'] ?? [])->toBe([]);

    $fila = DB::table('dispensaciones')->where('id', $id)->first();
    expect($fila)->not->toBeNull()
        ->and($fila->estado)->toBe('PENDIENTE_AUTORIZACION')
        ->and((int) $fila->dispensado_por_id)->toBe($creador->id)
        ->and($fila->autorizado_por_id)->toBeNull()
        ->and($fila->autorizado_en)->toBeNull()
        ->and($fila->rechazado_por_id)->toBeNull()
        ->and($fila->rechazado_en)->toBeNull()
        ->and($fila->motivo_rechazo)->toBeNull();

    $esperados = [];
    foreach ($cantidades as $i => $cantidad) {
        if ($cantidad !== null) {
            $esperados[$s['lineas'][$i]['item']->id] = $cantidad;
        }
    }
    $items = DB::table('dispensacion_items')->where('dispensacion_id', $id)->get()
        ->mapWithKeys(fn (object $f): array => [(int) $f->prescripcion_item_id => (int) $f->cantidad])->all();
    ksort($esperados);
    ksort($items);
    expect($items)->toBe($esperados);

    expect(DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->count())->toBe(0)
        ->and(rn05MovimientosDe($id))->toHaveCount(0);

    return $id;
}

/**
 * Crea una pendiente por HTTP y comprueba que no tocó el stock ni el kardex.
 *
 * @param  array<string, mixed>  $s
 * @param  list<int|null>  $cantidades
 */
function rn05CrearPendiente(User $creador, array $s, array $cantidades, ?string $clave = null): int
{
    $stocks = rn05Stocks($s);
    $kardex = DB::table('movimientos_kardex')->count();

    $id = rn05AfirmarPendiente(rn05Crear($creador, $s, $cantidades, $clave), $s, $cantidades, $creador);

    expect(rn05Stocks($s))->toBe($stocks)
        ->and(DB::table('movimientos_kardex')->count())->toBe($kardex);

    return $id;
}

/**
 * 200 CONFIRMADA tras autorizar: autorizado_por_id / autorizado_en (= ahora), detalles y
 * SALIDA_DISPENSACION por existencia según `$descuentos`, fechados en el instante de autorizar;
 * el resto de existencias del escenario sin cambios; kardex encadenado.
 *
 * @param  array<string, mixed>  $s
 * @param  array<int, int>  $stocksAntes  existencia_id → stock antes de autorizar
 * @param  array<int, int>  $descuentos  existencia_id → unidades que deben salir
 */
function rn05AfirmarAutorizada(TestResponse $r, int $id, User $autorizador, User $creador, array $s, array $stocksAntes, array $descuentos): void
{
    $r->assertOk();
    $d = cuerpoDatos($r);
    expect($d['id'] ?? null)->toBe($id)
        ->and($d['estado'] ?? null)->toBe('CONFIRMADA')
        ->and($d['dispensado_por_id'] ?? null)->toBe($creador->id)
        ->and($d['autorizado_por_id'] ?? null)->toBe($autorizador->id)
        ->and($d['autorizado_en'] ?? null)->toBeString()
        ->and(Carbon::parse($d['autorizado_en'])->equalTo(now()))->toBeTrue('autorizado_en no es el instante de la autorización');

    $fila = DB::table('dispensaciones')->where('id', $id)->first();
    expect($fila->estado)->toBe('CONFIRMADA')
        ->and((int) $fila->dispensado_por_id)->toBe($creador->id)
        ->and((int) $fila->autorizado_por_id)->toBe($autorizador->id)
        ->and($fila->autorizado_en)->not->toBeNull()
        ->and(Carbon::parse($fila->autorizado_en)->equalTo(now()))->toBeTrue('autorizado_en (BD) no es el instante de la autorización')
        ->and($fila->rechazado_por_id)->toBeNull()
        ->and($fila->motivo_rechazo)->toBeNull();

    $descuentos = array_filter($descuentos, fn (int $n): bool => $n > 0);

    // Stock: baja exactamente lo esperado en cada existencia; las demás, intactas.
    foreach ($stocksAntes as $existenciaId => $antes) {
        expect((int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad'))
            ->toBe($antes - ($descuentos[$existenciaId] ?? 0), "existencia {$existenciaId}");
    }

    // Kardex: solo SALIDA_DISPENSACION de esta dispensación, por existencia, en el instante de autorizar.
    $movimientos = rn05MovimientosDe($id);
    expect($movimientos->pluck('tipo')->unique()->values()->all())->toBe(['SALIDA_DISPENSACION'])
        // S-58: las salidas van a nombre del regente que autoriza; dispensado_por_id sigue siendo el creador.
        ->and($movimientos->pluck('usuario_id')->map(fn ($u): int => (int) $u)->unique()->values()->all())->toBe([$autorizador->id])
        ->and($autorizador->id)->not->toBe((int) $fila->dispensado_por_id);
    $porExistencia = [];
    foreach ($movimientos as $m) {
        $porExistencia[(int) $m->existencia_id] = ($porExistencia[(int) $m->existencia_id] ?? 0) - (int) $m->cantidad;
        expect(Carbon::parse($m->created_at)->equalTo(now()))->toBeTrue('la salida no se registró al autorizar');
    }
    ksort($porExistencia);
    ksort($descuentos);
    expect($porExistencia)->toBe($descuentos);

    // Detalles: por existencia igual a los descuentos y, por ítem, igual a la cantidad pedida.
    $detalles = DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->get();
    $detPorExistencia = [];
    foreach ($detalles as $det) {
        $detPorExistencia[(int) $det->existencia_id] = ($detPorExistencia[(int) $det->existencia_id] ?? 0) + (int) $det->cantidad;
    }
    ksort($detPorExistencia);
    expect($detPorExistencia)->toBe($descuentos);

    foreach (DB::table('dispensacion_items')->where('dispensacion_id', $id)->get() as $item) {
        expect((int) $detalles->where('dispensacion_item_id', $item->id)->sum('cantidad'))->toBe((int) $item->cantidad);
    }

    foreach ($s['lineas'] as $linea) {
        foreach ($linea['existencias'] as $existencia) {
            rn05AfirmarCadena($existencia);
        }
    }
}

/**
 * Descuentos esperados cuando cada línea tiene un solo lote: existencia_id → cantidad pedida.
 *
 * @param  array<string, mixed>  $s
 * @param  list<int|null>  $cantidades
 * @return array<int, int>
 */
function rn05DescuentosUnLote(array $s, array $cantidades): array
{
    $descuentos = [];
    foreach ($cantidades as $i => $cantidad) {
        if ($cantidad !== null) {
            $descuentos[$s['lineas'][$i]['existencias'][0]->id] = $cantidad;
        }
    }

    return $descuentos;
}

/** 409 STOCK_INSUFICIENTE con los detalles de S-02 / S-42. */
function rn05AfirmarInsuficiente(TestResponse $r, int $solicitado, int $disponible, int $vencido): void
{
    esperarError($r, 409, 'STOCK_INSUFICIENTE');
    expect($r->json('error.detalles.solicitado'))->toBe($solicitado)
        ->and($r->json('error.detalles.disponible'))->toBe($disponible)
        ->and($r->json('error.detalles.vencido_no_dispensable'))->toBe($vencido);
}

/** Ejecuta SQL que debe fallar dentro de un savepoint y devuelve el SQLSTATE (null si no falló). */
function rn05Sqlstate(Closure $operacion): ?string
{
    try {
        DB::transaction($operacion);
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

describe('RN-05 control especial: autorización de un segundo regente', function () {
    beforeEach(function () {
        rn05Congelar();
    });

    /*
    | AC1: sin control especial → CONFIRMADA directa
    */

    it('RN-05-AC1: sin productos de control especial → 201 CONFIRMADA sin autorización, stock descontado y SALIDA_DISPENSACION', function (string $rol, string $composicion) {
        $s = rn05Escenario(rn05Lineas($composicion));
        $cantidades = rn05Composicion($composicion);
        $creador = rn05Usuario($rol, 'creador');
        $stocks = rn05Stocks($s);

        $r = rn05Crear($creador, $s, $cantidades);

        $r->assertCreated();
        $d = cuerpoDatos($r);
        $id = $d['id'];
        expect($id)->toBeInt()
            ->and($d['estado'])->toBe('CONFIRMADA')
            ->and(array_key_exists('autorizado_por_id', $d) ? $d['autorizado_por_id'] : 'falta')->toBeNull()
            ->and(array_key_exists('autorizado_en', $d) ? $d['autorizado_en'] : 'falta')->toBeNull();

        $fila = DB::table('dispensaciones')->where('id', $id)->first();
        expect($fila->estado)->toBe('CONFIRMADA')
            ->and($fila->autorizado_por_id)->toBeNull()
            ->and($fila->autorizado_en)->toBeNull()
            ->and($fila->rechazado_por_id)->toBeNull();

        $descuentos = rn05DescuentosUnLote($s, $cantidades);
        foreach ($stocks as $existenciaId => $antes) {
            expect((int) DB::table('existencias')->where('id', $existenciaId)->value('cantidad'))->toBe($antes - ($descuentos[$existenciaId] ?? 0));
        }
        $movimientos = rn05MovimientosDe($id);
        expect($movimientos->pluck('tipo')->unique()->values()->all())->toBe(['SALIDA_DISPENSACION'])
            ->and((int) $movimientos->sum('cantidad'))->toBe(-array_sum($cantidades))
            ->and($movimientos)->toHaveCount(count($cantidades));
    })->with(['auxiliar_farmacia', 'regente_farmacia'])->with(['un ítem sin control', 'dos ítems sin control']);

    /*
    | AC2: con control especial → PENDIENTE_AUTORIZACION sin stock ni kardex
    */

    it('RN-05-AC2: con algún producto de control especial creada por un auxiliar → 201 PENDIENTE_AUTORIZACION; sin stock ni kardex; todos los ítems guardados (mixta → pendiente entera)', function (string $composicion) {
        $s = rn05Escenario(rn05Lineas($composicion));
        $antes = rn05Instantanea();

        $id = rn05CrearPendiente(rn05Auxiliar(), $s, rn05Composicion($composicion));

        $despues = rn05Instantanea();
        expect($despues['existencias'])->toBe($antes['existencias'])
            ->and($despues['kardex'])->toBe($antes['kardex'])
            ->and($despues['dispensacion_detalles'])->toBe($antes['dispensacion_detalles'])
            ->and($despues['dispensaciones'])->toHaveCount(count($antes['dispensaciones']) + 1)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with(['solo control', 'control + sin control', 'sin control + control', 'sin control, sin control, control']);

    it('RN-05-AC2 S-06: una dispensación de control creada por un regente también queda PENDIENTE_AUTORIZACION (necesita a otro regente)', function (string $composicion) {
        $s = rn05Escenario(rn05Lineas($composicion));
        $antes = rn05Instantanea();

        $id = rn05CrearPendiente(rn05Regente('r1'), $s, rn05Composicion($composicion));

        expect(rn05Instantanea()['existencias'])->toBe($antes['existencias'])
            ->and(rn05Instantanea()['kardex'])->toBe($antes['kardex'])
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with(['solo control', 'control + sin control']);

    it('RN-05-AC2 S-06: la pendiente no reserva stock: con 5 u. del producto sin control pedidas en una pendiente mixta, otra dispensación normal puede llevarse las 5', function () {
        $s = rn05Escenario([
            ['control' => true, 'prescrito' => 10, 'lotes' => [[10]]],
            ['control' => false, 'prescrito' => 10, 'lotes' => [[5]]],
        ]);
        rn05CrearPendiente(rn05Auxiliar(), $s, [2, 5]);

        $otra = Prescripcion::factory()->create();
        $otroItem = PrescripcionItem::factory()->create([
            'prescripcion_id' => $otra->id,
            'producto_id' => $s['lineas'][1]['producto']->id,
            'cantidad' => 5,
        ]);
        Sanctum::actingAs(rn05Auxiliar('otro'));
        $r = test()->postJson(RN05_URI, [
            'paciente_id' => $otra->paciente_id,
            'prescripcion_id' => $otra->id,
            'bodega_id' => $s['bodega']->id,
            'items' => [['prescripcion_item_id' => $otroItem->id, 'cantidad' => 5]],
        ], cabecerasDispensacion());

        $r->assertCreated();
        expect(cuerpoDatos($r)['estado'])->toBe('CONFIRMADA')
            ->and(rn05StockDe($s['lineas'][1]['existencias'][0]))->toBe(0)
            ->and(rn05StockDe($s['lineas'][0]['existencias'][0]))->toBe(10);
    });

    it('RN-05-AC2 RN-04-AC9 S-06: los ítems de una pendiente cuentan para el tope: con 6 pendientes de 10, pedir 5 → 422 CANTIDAD_EXCEDE_PRESCRITO (pendiente 4) sin cambios; pedir 4 → 201 pendiente', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[50]]]]);
        rn05CrearPendiente(rn05Auxiliar(), $s, [6]);
        $antes = rn05Instantanea();

        $r = rn05Crear(rn05Auxiliar(), $s, [5]);

        esperarError($r, 422, 'CANTIDAD_EXCEDE_PRESCRITO');
        expect($r->json('error.detalles.prescripcion_item_id'))->toBe($s['lineas'][0]['item']->id)
            ->and($r->json('error.detalles.pendiente'))->toBe(4)
            ->and($r->json('error.detalles.solicitado'))->toBe(5)
            ->and(rn05Instantanea())->toBe($antes);

        rn05CrearPendiente(rn05Auxiliar(), $s, [4]);
    });

    /*
    | AC3: un regente distinto autoriza → CONFIRMADA con salida FEFO y kardex
    */

    it('RN-05-AC3: un regente distinto del creador autoriza → 200 CONFIRMADA con autorizado_por_id y autorizado_en; salida de todos los ítems con SALIDA_DISPENSACION', function (string $rolCreador, string $composicion) {
        $s = rn05Escenario(rn05Lineas($composicion));
        $cantidades = rn05Composicion($composicion);
        $creador = rn05Usuario($rolCreador, 'creador');
        $id = rn05CrearPendiente($creador, $s, $cantidades);

        // La autorización llega más tarde: autorizado_en y el kardex deben llevar ESTE instante.
        rn05Congelar(RN05_HOY.' 11:30:00');
        $autorizador = rn05Regente('r2');
        $stocks = rn05Stocks($s);

        $r = rn05Autorizar($id, $autorizador);

        rn05AfirmarAutorizada($r, $id, $autorizador, $creador, $s, $stocks, rn05DescuentosUnLote($s, $cantidades));
    })->with([
        'creada por auxiliar' => 'auxiliar_farmacia',
        'creada por regente' => 'regente_farmacia',
    ])->with(['solo control', 'control + sin control', 'sin control, sin control, control']);

    it('RN-05-AC3: la salida al autorizar es FEFO por vencimiento (no por creación): ignora lotes vencidos intercalados (ayer y hoy, S-01) y lotes de otra bodega; cruza 1, 2 y 3 lotes', function (int $cantidad, array $esperado) {
        $bodega = Bodega::factory()->create();
        $otraBodega = Bodega::factory()->create();
        $producto = Producto::factory()->controlEspecial()->create();
        // Orden de creación distinto del de vencimiento.
        $lotes = [
            'L90' => rn05Lote($bodega, $producto, 10, 90),
            'L30' => rn05Lote($bodega, $producto, 3, 30),
            'Lhoy' => rn05Lote($bodega, $producto, 50, 0),
            'L60' => rn05Lote($bodega, $producto, 2, 60),
            'Layer' => rn05Lote($bodega, $producto, 50, -1),
            'L1' => rn05Lote($bodega, $producto, 0, 1),
        ];
        $ajena = rn05Lote($otraBodega, $producto, 100, 5);
        $prescripcion = Prescripcion::factory()->create();
        $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 20]);
        $s = ['bodega' => $bodega, 'prescripcion' => $prescripcion, 'lineas' => [
            ['producto' => $producto, 'item' => $item, 'existencias' => array_values($lotes)],
        ]];
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [$cantidad]);
        $stocks = rn05Stocks($s) + [$ajena->id => 100];

        $autorizador = rn05Regente('r2');
        $descuentos = [];
        foreach ($esperado as $nombre => $n) {
            $descuentos[$lotes[$nombre]->id] = $n;
        }

        rn05AfirmarAutorizada(rn05Autorizar($id, $autorizador), $id, $autorizador, $creador, $s, $stocks, $descuentos);
        expect(rn05StockDe($ajena))->toBe(100);
    })->with([
        'cabe en el primer lote' => [2, ['L30' => 2]],
        'agota el primero exacto' => [3, ['L30' => 3]],
        'cruza 2 lotes' => [5, ['L30' => 3, 'L60' => 2]],
        'cruza 3 lotes' => [6, ['L30' => 3, 'L60' => 2, 'L90' => 1]],
        'todo el stock vigente' => [15, ['L30' => 3, 'L60' => 2, 'L90' => 10]],
    ]);

    it('RN-05-AC3 S-03: al autorizar, a igual vencimiento sale primero el lote de menor id', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->controlEspecial()->create();
        $primero = rn05Lote($bodega, $producto, 2, 40);
        $segundo = rn05Lote($bodega, $producto, 5, 40);
        $prescripcion = Prescripcion::factory()->create();
        $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 10]);
        $s = ['bodega' => $bodega, 'prescripcion' => $prescripcion, 'lineas' => [
            ['producto' => $producto, 'item' => $item, 'existencias' => [$primero, $segundo]],
        ]];
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [3]);
        $autorizador = rn05Regente('r2');

        $stocksPrevios = rn05Stocks($s);

        rn05AfirmarAutorizada(rn05Autorizar($id, $autorizador), $id, $autorizador, $creador, $s, $stocksPrevios, [
            $primero->id => 2,
            $segundo->id => 1,
        ]);
    });

    it('RN-05-AC3 S-06: el FEFO se evalúa al autorizar: un lote que vence entre la creación y la autorización se ignora y un lote que entra después con vencimiento más próximo sale primero', function () {
        $bodega = Bodega::factory()->create();
        $producto = Producto::factory()->controlEspecial()->create();
        $venceManana = rn05Lote($bodega, $producto, 4, 1);
        $lejano = rn05Lote($bodega, $producto, 10, 120);
        $prescripcion = Prescripcion::factory()->create();
        $item = PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'producto_id' => $producto->id, 'cantidad' => 10]);
        $s = ['bodega' => $bodega, 'prescripcion' => $prescripcion, 'lineas' => [
            ['producto' => $producto, 'item' => $item, 'existencias' => [$venceManana, $lejano]],
        ]];
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [6]);

        // Al día siguiente el primer lote vence hoy (vencido, S-01) y entra un lote que vence en 20 días.
        rn05Congelar(rn05Dia(1).' 09:00:00');
        $nuevo = rn05Lote($bodega, $producto, 3, 20);
        $s['lineas'][0]['existencias'][] = $nuevo;
        $autorizador = rn05Regente('r2');

        $stocksPrevios = rn05Stocks($s);

        rn05AfirmarAutorizada(rn05Autorizar($id, $autorizador), $id, $autorizador, $creador, $s, $stocksPrevios, [
            $nuevo->id => 3,
            $lejano->id => 3,
        ]);
        expect(rn05StockDe($venceManana))->toBe(4);
    });

    it('RN-05-AC3 S-19: con la semilla, regente@farma.test crea la dispensación de control y regente2@farma.test la autoriza', function () {
        $this->seed(UsuariosSeeder::class);
        $r1 = User::query()->where('email', 'regente@farma.test')->firstOrFail();
        $r2 = User::query()->where('email', 'regente2@farma.test')->firstOrFail();
        $s = rn05Escenario(rn05Lineas('control + sin control'));
        $id = rn05CrearPendiente($r1, $s, [4, 3]);

        esperarError(rn05Autorizar($id, $r1), 403, 'AUTORIZACION_MISMO_USUARIO');
        $stocksPrevios = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $r2), $id, $r2, $r1, $s, $stocksPrevios, rn05DescuentosUnLote($s, [4, 3]));
    });

    /*
    | AC4: el mismo regente no puede autorizar ni rechazar lo que creó
    */

    it('RN-05-AC4: el regente R1 que creó la dispensación de control no puede autorizarla ni rechazarla → 403 AUTORIZACION_MISMO_USUARIO; sigue pendiente sin cambios y otro regente sí puede autorizarla', function (string $accion) {
        $s = rn05Escenario(rn05Lineas('control + sin control'));
        $r1 = rn05Regente('r1');
        $id = rn05CrearPendiente($r1, $s, [4, 3]);
        $antes = rn05Instantanea();

        $r = match ($accion) {
            'autorizar' => rn05Autorizar($id, $r1),
            'rechazar con motivo' => rn05Rechazar($id, $r1),
            // S-50: el 403 se decide antes que la validación del cuerpo (422).
            'rechazar sin motivo' => rn05Rechazar($id, $r1, []),
        };

        esperarError($r, 403, 'AUTORIZACION_MISMO_USUARIO');
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');

        $r2 = rn05Regente('r2');
        $stocksPrevios = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $r2), $id, $r2, $r1, $s, $stocksPrevios, rn05DescuentosUnLote($s, [4, 3]));
    })->with(['autorizar', 'rechazar con motivo', 'rechazar sin motivo']);

    /*
    | AC5: autorizar / rechazar solo regente
    */

    it('RN-05-AC5: un usuario sin rol regente (incluido el auxiliar que la creó) no puede autorizar ni rechazar → 403 NO_AUTORIZADO sin cambios', function (string $quien, string $accion) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $creador = rn05Auxiliar('creador');
        $id = rn05CrearPendiente($creador, $s, [4]);
        $antes = rn05Instantanea();

        $usuario = match ($quien) {
            'auxiliar creador' => $creador,
            'otro auxiliar' => rn05Auxiliar('otro'),
            'medico' => rn05Usuario('medico', 'medico'),
            'auditor' => rn05Usuario('auditor', 'auditor'),
            'admin (S-13)' => rn05Usuario('admin', 'admin'),
        };
        $r = match ($accion) {
            'autorizar' => rn05Autorizar($id, $usuario),
            'rechazar' => rn05Rechazar($id, $usuario),
            // S-50: 403 antes que 422.
            'rechazar sin motivo' => rn05Rechazar($id, $usuario, []),
        };

        esperarError($r, 403, 'NO_AUTORIZADO');
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with(['auxiliar creador', 'otro auxiliar', 'medico', 'auditor', 'admin (S-13)'])
        ->with(['autorizar', 'rechazar', 'rechazar sin motivo']);

    it('RN-05-AC5: sin token o con token inválido → 401 NO_AUTENTICADO sin cambios', function (string $credencial, string $accion) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [4]);
        $antes = rn05Instantanea();
        olvidarAutenticacion();

        if ($credencial === 'token inválido') {
            $this->withHeader('Authorization', 'Bearer 999999|token-inventado-rn05');
        }
        $r = rn05Accion($accion, $id);

        esperarError($r, 401, 'NO_AUTENTICADO');
        expect(rn05Instantanea())->toBe($antes);
    })->with(['sin token', 'token inválido'])->with(['autorizar', 'rechazar']);

    it('RN-05-AC5 RN-05-AC3: id inexistente o no numérico en /dispensaciones/{id}/autorizar|rechazar → 404 NO_ENCONTRADO sin cambios (nunca 500)', function (string $id, string $accion) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        rn05CrearPendiente(rn05Auxiliar(), $s, [4]);
        $antes = rn05Instantanea();

        $r = rn05Accion($accion, $id, rn05Regente('r2'));

        esperarError($r, 404, 'NO_ENCONTRADO');
        expect($r->getContent())->not->toContain('SQLSTATE')
            ->and(rn05Instantanea())->toBe($antes);
    })->with([
        'inexistente' => '987654321',
        'no numérico' => 'abc',
        'decimal' => '1.5',
        'negativo' => '-1',
        'fuera de bigint' => '9223372036854775808',
    ])->with(['autorizar', 'rechazar']);

    /*
    | AC6: el stock ya no alcanza al autorizar
    */

    it('RN-05-AC6: pendiente cuyo stock vigente ya no alcanza → 409 STOCK_INSUFICIENTE, sigue PENDIENTE_AUTORIZACION sin kardex ni descuento parcial; tras reponer, autoriza 200', function (string $causa) {
        $creador = rn05Auxiliar();
        $autorizador = rn05Regente('r2');

        // Cada causa deja preparado: el escenario, la pendiente, el error esperado y cómo reponer.
        switch ($causa) {
            case 'ajuste negativo':
                $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[5]]]]);
                $id = rn05CrearPendiente($creador, $s, [5]);
                $existencia = $s['lineas'][0]['existencias'][0];
                Sanctum::actingAs(rn05Regente('ajustador'));
                test()->postJson(RN05_URI_AJUSTES, [
                    'existencia_id' => $existencia->id,
                    'cantidad' => -3,
                    'motivo' => 'Rotura de ampollas en estante',
                ])->assertSuccessful();
                expect(rn05StockDe($existencia))->toBe(2);
                $error = [5, 2, 0];
                $reponer = fn () => rn05Entrada($existencia, 3);
                $descuentos = [$existencia->id => 5];
                break;

            case 'otra pendiente autorizada antes':
                $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[5]]]]);
                $existencia = $s['lineas'][0]['existencias'][0];
                $primera = rn05CrearPendiente(rn05Auxiliar('otro'), $s, [3]);
                $id = rn05CrearPendiente($creador, $s, [3]);
                rn05Autorizar($primera, rn05Regente('r3'))->assertOk();
                expect(rn05StockDe($existencia))->toBe(2);
                $error = [3, 2, 0];
                $reponer = fn () => rn05Entrada($existencia, 1);
                $descuentos = [$existencia->id => 3];
                break;

            case 'el lote venció antes de autorizar (S-01)':
                $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[5, 2]]]]);
                $id = rn05CrearPendiente($creador, $s, [5]);
                rn05Congelar(rn05Dia(2).' 08:00:00');
                $error = [5, 0, 5];
                $reponer = function () use (&$s): void {
                    $s['lineas'][0]['existencias'][] = rn05Lote($s['bodega'], $s['lineas'][0]['producto'], 5, 100);
                };
                $descuentos = null; // se calcula tras reponer (lote nuevo)
                break;

            case 'mixta: el producto sin control se agotó con otra dispensación':
                $s = rn05Escenario([
                    ['control' => true, 'prescrito' => 10, 'lotes' => [[10]]],
                    ['control' => false, 'prescrito' => 10, 'lotes' => [[5]]],
                ]);
                $id = rn05CrearPendiente($creador, $s, [2, 5]);
                $otra = Prescripcion::factory()->create();
                $otroItem = PrescripcionItem::factory()->create(['prescripcion_id' => $otra->id, 'producto_id' => $s['lineas'][1]['producto']->id, 'cantidad' => 5]);
                Sanctum::actingAs(rn05Auxiliar('otro'));
                test()->postJson(RN05_URI, [
                    'paciente_id' => $otra->paciente_id,
                    'prescripcion_id' => $otra->id,
                    'bodega_id' => $s['bodega']->id,
                    'items' => [['prescripcion_item_id' => $otroItem->id, 'cantidad' => 5]],
                ], cabecerasDispensacion())->assertCreated();
                $error = [5, 0, 0];
                $sinControl = $s['lineas'][1]['existencias'][0];
                $reponer = fn () => rn05Entrada($sinControl, 5);
                $descuentos = [$s['lineas'][0]['existencias'][0]->id => 2, $sinControl->id => 5];
                break;

            default:
                throw new InvalidArgumentException($causa);
        }

        $antes = rn05Instantanea();

        $r = rn05Autorizar($id, $autorizador);

        rn05AfirmarInsuficiente($r, ...$error);
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION')
            ->and(rn05MovimientosDe($id))->toHaveCount(0)
            ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->count())->toBe(0);

        $reponer();
        if ($descuentos === null) {
            $descuentos = [end($s['lineas'][0]['existencias'])->id => 5];
        }
        $stocks = rn05Stocks($s);

        rn05AfirmarAutorizada(rn05Autorizar($id, $autorizador), $id, $autorizador, $creador, $s, $stocks, $descuentos);
    })->with([
        'ajuste negativo',
        'otra pendiente autorizada antes',
        'el lote venció antes de autorizar (S-01)',
        'mixta: el producto sin control se agotó con otra dispensación',
    ]);

    it('RN-05-AC6 RN-05-AC8: tras un 409 STOCK_INSUFICIENTE la pendiente sigue viva y un regente distinto puede rechazarla', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[5]]]]);
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [5]);
        Sanctum::actingAs(rn05Regente('ajustador'));
        test()->postJson(RN05_URI_AJUSTES, [
            'existencia_id' => $s['lineas'][0]['existencias'][0]->id,
            'cantidad' => -1,
            'motivo' => 'Ampolla rota durante inventario',
        ])->assertSuccessful();

        rn05AfirmarInsuficiente(rn05Autorizar($id, rn05Regente('r2')), 5, 4, 0);
        $r = rn05Rechazar($id, rn05Regente('r3'));

        $r->assertOk();
        expect(cuerpoDatos($r)['estado'])->toBe('RECHAZADA')
            ->and(rn05Estado($id))->toBe('RECHAZADA')
            ->and(rn05MovimientosDe($id))->toHaveCount(0)
            ->and(rn05StockDe($s['lineas'][0]['existencias'][0]))->toBe(4);
    });

    /*
    | AC7: CONFIRMADA o RECHAZADA no admiten autorizar ni rechazar
    */

    it('RN-05-AC7: sobre una dispensación CONFIRMADA o RECHAZADA, autorizar o rechazar → 409 TRANSICION_INVALIDA sin cambios', function (string $estado, string $accion) {
        $creador = rn05Auxiliar();
        switch ($estado) {
            case 'CONFIRMADA sin control':
                $s = rn05Escenario(rn05Lineas('un ítem sin control'));
                $r = rn05Crear($creador, $s, [4]);
                $r->assertCreated();
                $id = cuerpoDatos($r)['id'];
                break;
            case 'CONFIRMADA autorizada':
                $s = rn05Escenario(rn05Lineas('control + sin control'));
                $id = rn05CrearPendiente($creador, $s, [4, 3]);
                rn05Autorizar($id, rn05Regente('r2'))->assertOk();
                break;
            case 'RECHAZADA':
                $s = rn05Escenario(rn05Lineas('control + sin control'));
                $id = rn05CrearPendiente($creador, $s, [4, 3]);
                rn05Rechazar($id, rn05Regente('r2'))->assertOk();
                break;
            default:
                throw new InvalidArgumentException($estado);
        }
        $estadoAntes = rn05Estado($id);
        $antes = rn05Instantanea();

        // R3: regente que no creó ni resolvió la dispensación (el único error posible es el de estado).
        $r = rn05Accion($accion, $id, rn05Regente('r3'));

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe($estadoAntes);
    })->with(['CONFIRMADA sin control', 'CONFIRMADA autorizada', 'RECHAZADA'])->with(['autorizar', 'rechazar']);

    it('RN-05-AC7: autorizar dos veces seguidas la misma pendiente → la segunda 409 TRANSICION_INVALIDA, una sola salida de stock', function (string $segundo) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [4]);
        $r2 = rn05Regente('r2');
        $stocksPrevios = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $r2), $id, $r2, $creador, $s, $stocksPrevios, rn05DescuentosUnLote($s, [4]));
        $antes = rn05Instantanea();

        $quien = $segundo === 'el mismo autorizador' ? $r2 : rn05Regente('r3');
        esperarError(rn05Autorizar($id, $quien), 409, 'TRANSICION_INVALIDA');

        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05MovimientosDe($id))->toHaveCount(1)
            ->and(rn05StockDe($s['lineas'][0]['existencias'][0]))->toBe(16);
    })->with(['el mismo autorizador', 'otro regente']);

    /*
    | AC8: rechazar con motivo
    */

    it('RN-05-AC8: un regente distinto rechaza con motivo → 200 RECHAZADA con rechazado_por_id, rechazado_en y motivo_rechazo; sin movimientos ni cambios de stock', function (string $motivo) {
        $s = rn05Escenario(rn05Lineas('control + sin control'));
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [4, 3]);
        rn05Congelar(RN05_HOY.' 12:15:00');
        $antes = rn05Instantanea();
        $rechazador = rn05Regente('r2');

        $r = rn05Rechazar($id, $rechazador, ['motivo' => $motivo]);

        $r->assertOk();
        $d = cuerpoDatos($r);
        expect($d['id'])->toBe($id)
            ->and($d['estado'])->toBe('RECHAZADA')
            ->and($d['rechazado_por_id'] ?? null)->toBe($rechazador->id)
            ->and($d['motivo_rechazo'] ?? null)->toBe($motivo)
            ->and(array_key_exists('autorizado_por_id', $d) ? $d['autorizado_por_id'] : 'falta')->toBeNull();

        $fila = DB::table('dispensaciones')->where('id', $id)->first();
        expect($fila->estado)->toBe('RECHAZADA')
            ->and((int) $fila->rechazado_por_id)->toBe($rechazador->id)
            ->and(Carbon::parse($fila->rechazado_en)->equalTo(now()))->toBeTrue('rechazado_en no es el instante del rechazo')
            ->and($fila->motivo_rechazo)->toBe($motivo)
            ->and((int) $fila->dispensado_por_id)->toBe($creador->id)
            ->and($fila->autorizado_por_id)->toBeNull()
            ->and($fila->autorizado_en)->toBeNull();

        $despues = rn05Instantanea();
        expect($despues['existencias'])->toBe($antes['existencias'])
            ->and($despues['kardex'])->toBe($antes['kardex'])
            ->and($despues['dispensacion_detalles'])->toBe($antes['dispensacion_detalles'])
            ->and($despues['dispensacion_items'])->toBe($antes['dispensacion_items'])
            ->and(rn05MovimientosDe($id))->toHaveCount(0);
    })->with([
        'texto normal' => RN05_MOTIVO,
        'exactamente 10 caracteres' => 'Sin firmas',
        '10 caracteres multibyte' => 'Ñandú ñoño',
        'exactamente 500 caracteres' => str_repeat('a', 500),
    ]);

    it('RN-05-AC8: motivo ausente, vacío, corto, largo, no texto o con caracteres de control → 422 VALIDACION (campo motivo); sigue pendiente sin cambios', function (array $cuerpo) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [4]);
        $antes = rn05Instantanea();

        $r = rn05Rechazar($id, rn05Regente('r2'), $cuerpo);

        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('motivo')
            ->and(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with([
        'sin motivo' => [[]],
        'motivo null' => [['motivo' => null]],
        'cadena vacía' => [['motivo' => '']],
        'solo espacios' => [['motivo' => '            ']],
        '9 caracteres' => [['motivo' => 'Sin firma']],
        '9 caracteres rodeados de espacios' => [['motivo' => '   Sin firma   ']],
        '501 caracteres' => [['motivo' => str_repeat('a', 501)]],
        'número' => [['motivo' => 1234567890]],
        'arreglo' => [['motivo' => ['Motivo en arreglo']]],
        'salto de línea' => [['motivo' => "Falta soporte\nmédico"]],
        'tabulador' => [['motivo' => "Falta soporte\tmédico"]],
        'carácter nulo' => [['motivo' => "Falta soporte\0médico"]],
        'invisible de formato (Cf, U+200B)' => [['motivo' => "Falta soporte\u{200B}médico"]],
        'separador de línea U+2028' => [['motivo' => "Falta soporte\u{2028}médico"]],
    ]);

    it('RN-05-AC8 RN-04-AC9 S-06: tras rechazar, lo prescrito vuelve a estar pendiente: antes 1 u. más → 422 (pendiente 0); después, una nueva dispensación por el total → 201', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[30]]]]);
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [10]);

        $r = rn05Crear(rn05Auxiliar(), $s, [1]);
        esperarError($r, 422, 'CANTIDAD_EXCEDE_PRESCRITO');
        expect($r->json('error.detalles.pendiente'))->toBe(0);

        rn05Rechazar($id, rn05Regente('r2'))->assertOk();

        $nueva = rn05CrearPendiente(rn05Auxiliar(), $s, [10]);
        expect($nueva)->not->toBe($id)
            ->and(rn05Estado($id))->toBe('RECHAZADA');

        // Y la nueva se puede autorizar por el total prescrito.
        $creador = rn05Auxiliar();
        $r2 = rn05Regente('r2');
        $stocksPrevios = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($nueva, $r2), $nueva, $r2, $creador, $s, $stocksPrevios, rn05DescuentosUnLote($s, [10]));
    });

    /*
    | AC9: la BD impide que el dispensador se autorice o se rechace a sí mismo
    */

    it('RN-05-AC9: SQL directo con autorizado_por_id o rechazado_por_id = dispensado_por_id → la BD lo rechaza (CHECK, 23514) y la fila no cambia', function (string $caso) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $creador = rn05Regente('r1');
        $id = rn05CrearPendiente($creador, $s, [4]);
        $antes = rn05Instantanea();
        $ahora = now();

        $sqlstate = rn05Sqlstate(function () use ($caso, $id, $creador, $s, $ahora): void {
            $base = [
                'paciente_id' => $s['prescripcion']->paciente_id,
                'prescripcion_id' => $s['prescripcion']->id,
                'bodega_id' => $s['bodega']->id,
                'dispensado_por_id' => $creador->id,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            match ($caso) {
                'UPDATE autoriza el propio dispensador' => DB::table('dispensaciones')->where('id', $id)->update([
                    'estado' => 'CONFIRMADA', 'autorizado_por_id' => $creador->id, 'autorizado_en' => $ahora,
                ]),
                'UPDATE rechaza el propio dispensador' => DB::table('dispensaciones')->where('id', $id)->update([
                    'estado' => 'RECHAZADA', 'rechazado_por_id' => $creador->id, 'rechazado_en' => $ahora, 'motivo_rechazo' => RN05_MOTIVO,
                ]),
                'INSERT autorizada por el propio dispensador' => DB::table('dispensaciones')->insert($base + [
                    'estado' => 'CONFIRMADA', 'autorizado_por_id' => $creador->id, 'autorizado_en' => $ahora,
                ]),
                'INSERT rechazada por el propio dispensador' => DB::table('dispensaciones')->insert($base + [
                    'estado' => 'RECHAZADA', 'rechazado_por_id' => $creador->id, 'rechazado_en' => $ahora, 'motivo_rechazo' => RN05_MOTIVO,
                ]),
                'UPDATE solo autorizado_por_id (con fecha) en la pendiente' => DB::table('dispensaciones')->where('id', $id)->update([
                    'autorizado_por_id' => DB::raw('dispensado_por_id'), 'autorizado_en' => $ahora, 'estado' => 'CONFIRMADA',
                ]),
            };
        });

        expect($sqlstate)->toBe('23514')
            ->and(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with([
        'UPDATE autoriza el propio dispensador',
        'UPDATE rechaza el propio dispensador',
        'INSERT autorizada por el propio dispensador',
        'INSERT rechazada por el propio dispensador',
        'UPDATE solo autorizado_por_id (con fecha) en la pendiente',
    ]);

    it('RN-05-AC9: control del CHECK: la misma sentencia con un usuario distinto del dispensador sí la acepta la BD', function (string $caso) {
        $s = rn05Escenario(rn05Lineas('solo control'));
        $id = rn05CrearPendiente(rn05Regente('r1'), $s, [4]);
        $otro = rn05Regente('r2');
        $ahora = now();

        $sqlstate = rn05Sqlstate(fn () => match ($caso) {
            'autoriza otro' => DB::table('dispensaciones')->where('id', $id)->update([
                'estado' => 'CONFIRMADA', 'autorizado_por_id' => $otro->id, 'autorizado_en' => $ahora,
            ]),
            'rechaza otro' => DB::table('dispensaciones')->where('id', $id)->update([
                'estado' => 'RECHAZADA', 'rechazado_por_id' => $otro->id, 'rechazado_en' => $ahora, 'motivo_rechazo' => RN05_MOTIVO,
            ]),
        });

        expect($sqlstate)->toBeNull()
            ->and(rn05Estado($id))->toBe($caso === 'autoriza otro' ? 'CONFIRMADA' : 'RECHAZADA');
    })->with(['autoriza otro', 'rechaza otro']);

    /*
    | RN-09-AC8: idempotencia de una pendiente
    */

    it('RN-09-AC8 RN-05-AC2 RN-05-AC3: reintentar con la misma clave antes y después de autorizar reproduce el 201 PENDIENTE_AUTORIZACION original; no crea otra ni descuenta otra vez', function () {
        $s = rn05Escenario(rn05Lineas('control + sin control'));
        $creador = rn05Auxiliar();
        $clave = 'rn05-ac8-pendiente-'.uniqid();

        $original = rn05Crear($creador, $s, [4, 3], $clave);
        $id = rn05AfirmarPendiente($original, $s, [4, 3], $creador);
        expect($original->headers->get('Idempotent-Replayed'))->toBeNull();

        $antesReintento = rn05Instantanea();
        $reintento = rn05Crear($creador, $s, [4, 3], $clave);
        $reintento->assertStatus(201);
        expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($reintento->json())->toBe($original->json())
            ->and(rn05Instantanea())->toBe($antesReintento);

        $r2 = rn05Regente('r2');
        $stocksPrevios = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $r2), $id, $r2, $creador, $s, $stocksPrevios, rn05DescuentosUnLote($s, [4, 3]));
        $antesTardio = rn05Instantanea();

        $tardio = rn05Crear($creador, $s, [4, 3], $clave);

        $tardio->assertStatus(201);
        expect($tardio->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($tardio->json())->toBe($original->json())
            ->and(cuerpoDatos($tardio)['estado'])->toBe('PENDIENTE_AUTORIZACION')
            ->and(rn05Instantanea())->toBe($antesTardio)
            ->and(DB::table('dispensaciones')->where('dispensado_por_id', $creador->id)->count())->toBe(1)
            ->and(rn05MovimientosDe($id))->toHaveCount(2)
            ->and(rn05Estado($id))->toBe('CONFIRMADA');
    });

    it('RN-09-AC8 RN-05-AC8: tras rechazar, reintentar con la misma clave reproduce el 201 PENDIENTE original y no crea otra (aunque el tope vuelva a estar libre)', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[30]]]]);
        $creador = rn05Auxiliar();
        $clave = 'rn05-ac8-rechazada-'.uniqid();
        $original = rn05Crear($creador, $s, [10], $clave);
        $id = rn05AfirmarPendiente($original, $s, [10], $creador);
        rn05Rechazar($id, rn05Regente('r2'))->assertOk();
        $antes = rn05Instantanea();

        $reintento = rn05Crear($creador, $s, [10], $clave);

        $reintento->assertStatus(201);
        expect($reintento->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($reintento->json())->toBe($original->json())
            ->and(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('RECHAZADA');
    });
});

/*
|--------------------------------------------------------------------------
| Revalidación al autorizar: prescripción vigente (S-05) y bodega activa (S-46)
|--------------------------------------------------------------------------
|
| La pendiente se crea con la prescripción vigente y la bodega activa; mientras espera, la
| prescripción vence (el reloj pasa al día siguiente a su fecha_vencimiento) o se anula, o la bodega
| se desactiva. Autorizar revalida ambas porque es entonces cuando sale el stock; rechazar no.
| Precedencia al autorizar: 401 → 404 → 403 rol → 403 mismo usuario → 409 TRANSICION_INVALIDA →
| 422 VALIDACION (bodega) → 422 PRESCRIPCION_NO_VIGENTE → 409 STOCK_INSUFICIENTE.
*/

/**
 * Escenario de revalidación: prescripción que vence mañana (rn05Dia(1)), vigente al crear.
 *
 * @return array<string, mixed>
 */
function rn05EscenarioRevalidacion(string $composicion = 'control + sin control'): array
{
    $s = rn05Escenario(rn05Lineas($composicion));
    DB::table('prescripciones')->where('id', $s['prescripcion']->id)->update(['fecha_vencimiento' => rn05Dia(1)]);

    return $s;
}

/**
 * Deja la pendiente en la causa indicada y devuelve el cierre que la restaura.
 *
 * @param  array<string, mixed>  $s
 */
function rn05Invalidar(string $causa, array $s): Closure
{
    switch ($causa) {
        case 'prescripción vencida':
            // Primer instante del día siguiente al vencimiento (Bogotá): ya no vigente (S-05).
            rn05Congelar(rn05Dia(2).' 00:00:00');

            return fn () => rn05Congelar(rn05Dia(1).' 23:59:59');

        case 'prescripción anulada':
            DB::table('prescripciones')->where('id', $s['prescripcion']->id)->update(['estado' => 'ANULADA']);

            return fn () => DB::table('prescripciones')->where('id', $s['prescripcion']->id)->update(['estado' => 'ACTIVA']);

        case 'bodega desactivada':
            DB::table('bodegas')->where('id', $s['bodega']->id)->update(['activa' => false]);

            return fn () => DB::table('bodegas')->where('id', $s['bodega']->id)->update(['activa' => true]);

        default:
            throw new InvalidArgumentException("causa desconocida: {$causa}");
    }
}

/**
 * Error esperado para la causa: 422 PRESCRIPCION_NO_VIGENTE con id/estado/fecha (S-05) o 422
 * VALIDACION con bodega_id en detalles.campos (S-46), igual que al crear.
 *
 * @param  array<string, mixed>  $s
 */
function rn05AfirmarRevalidacion(TestResponse $r, string $causa, array $s): void
{
    if ($causa === 'bodega desactivada') {
        esperarError($r, 422, 'VALIDACION');
        expect($r->json('error.detalles.campos'))->toHaveKey('bodega_id');

        return;
    }

    esperarError($r, 422, 'PRESCRIPCION_NO_VIGENTE');
    expect($r->json('error.detalles'))->toBe([
        'prescripcion_id' => $s['prescripcion']->id,
        'estado' => $causa === 'prescripción anulada' ? 'ANULADA' : 'ACTIVA',
        'fecha_vencimiento' => rn05Dia(1),
    ]);
}

describe('RN-05 control especial: revalidación de prescripción y bodega al autorizar', function () {
    beforeEach(function () {
        rn05Congelar();
    });

    it('RN-05-AC3 S-05 S-46: si mientras espera la prescripción vence o se anula, o la bodega se desactiva, autorizar → 422 sin cambios y sigue PENDIENTE_AUTORIZACION; al restaurarla, autoriza 200', function (string $causa) {
        $s = rn05EscenarioRevalidacion();
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [4, 3]);
        $restaurar = rn05Invalidar($causa, $s);
        $antes = rn05Instantanea();
        $prescripcionAntes = (array) DB::table('prescripciones')->where('id', $s['prescripcion']->id)->first();
        $autorizador = rn05Regente('r2');

        $r = rn05Autorizar($id, $autorizador);

        rn05AfirmarRevalidacion($r, $causa, $s);
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION')
            ->and(rn05MovimientosDe($id))->toHaveCount(0)
            ->and(DB::table('dispensacion_detalles')->where('dispensacion_id', $id)->count())->toBe(0)
            ->and((array) DB::table('prescripciones')->where('id', $s['prescripcion']->id)->first())->toBe($prescripcionAntes);
        $fila = DB::table('dispensaciones')->where('id', $id)->first();
        expect($fila->autorizado_por_id)->toBeNull()
            ->and($fila->autorizado_en)->toBeNull();

        // El 422 no deja la pendiente inservible: restaurada la causa, otro intento autoriza.
        $restaurar();
        $stocks = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $autorizador), $id, $autorizador, $creador, $s, $stocks, rn05DescuentosUnLote($s, [4, 3]));
    })->with(['prescripción vencida', 'prescripción anulada', 'bodega desactivada']);

    it('RN-05-AC3 S-05: una prescripción que vence justo hoy sigue vigente al autorizar → 200 CONFIRMADA (del primer al último instante del día)', function (string $hora) {
        $s = rn05EscenarioRevalidacion('solo control');
        $creador = rn05Auxiliar();
        $id = rn05CrearPendiente($creador, $s, [4]);
        rn05Congelar(rn05Dia(1).' '.$hora);
        $autorizador = rn05Regente('r2');
        $stocks = rn05Stocks($s);

        $r = rn05Autorizar($id, $autorizador);

        rn05AfirmarAutorizada($r, $id, $autorizador, $creador, $s, $stocks, rn05DescuentosUnLote($s, [4]));
    })->with(['00:00:00', '23:59:59']);

    it('RN-05-AC8 S-05 S-46: rechazar no revalida: una pendiente con la prescripción vencida o anulada, o con la bodega desactivada, se rechaza → 200 RECHAZADA sin movimientos', function (string $causa) {
        $s = rn05EscenarioRevalidacion();
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [4, 3]);
        rn05Invalidar($causa, $s);
        $stocks = rn05Stocks($s);
        $kardex = DB::table('movimientos_kardex')->count();
        $rechazador = rn05Regente('r2');

        $r = rn05Rechazar($id, $rechazador);

        $r->assertOk();
        expect(cuerpoDatos($r)['estado'] ?? null)->toBe('RECHAZADA')
            ->and(cuerpoDatos($r)['rechazado_por_id'] ?? null)->toBe($rechazador->id)
            ->and(rn05Estado($id))->toBe('RECHAZADA')
            ->and(rn05MovimientosDe($id))->toHaveCount(0)
            ->and(DB::table('movimientos_kardex')->count())->toBe($kardex)
            ->and(rn05Stocks($s))->toBe($stocks);
    })->with(['prescripción vencida', 'prescripción anulada', 'bodega desactivada']);

    it('RN-05-AC7 S-05 S-46: sobre una CONFIRMADA o RECHAZADA con la prescripción no vigente o la bodega desactivada, autorizar → 409 TRANSICION_INVALIDA (no 422) sin cambios', function (string $estado, string $causa) {
        $s = rn05EscenarioRevalidacion();
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [4, 3]);
        match ($estado) {
            'CONFIRMADA' => rn05Autorizar($id, rn05Regente('r2'))->assertOk(),
            'RECHAZADA' => rn05Rechazar($id, rn05Regente('r2'))->assertOk(),
        };
        rn05Invalidar($causa, $s);
        $antes = rn05Instantanea();

        $r = rn05Autorizar($id, rn05Regente('r3'));

        esperarError($r, 409, 'TRANSICION_INVALIDA');
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe($estado);
    })->with(['CONFIRMADA', 'RECHAZADA'])->with(['prescripción vencida', 'prescripción anulada', 'bodega desactivada']);

    it('RN-05-AC4 RN-05-AC5 S-50: con la prescripción vencida, el creador → 403 AUTORIZACION_MISMO_USUARIO y un auxiliar o médico → 403 NO_AUTORIZADO (el 403 precede al 422)', function (string $quien) {
        $s = rn05EscenarioRevalidacion();
        $creador = rn05Regente('r1');
        $id = rn05CrearPendiente($creador, $s, [4, 3]);
        rn05Invalidar('prescripción vencida', $s);
        $antes = rn05Instantanea();

        [$como, $codigo] = match ($quien) {
            'el regente creador' => [$creador, 'AUTORIZACION_MISMO_USUARIO'],
            'un auxiliar' => [rn05Auxiliar('otro'), 'NO_AUTORIZADO'],
            'un médico' => [rn05Usuario('medico', 'medico'), 'NO_AUTORIZADO'],
        };

        esperarError(rn05Autorizar($id, $como), 403, $codigo);
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with(['el regente creador', 'un auxiliar', 'un médico']);

    it('RN-05-AC6 S-54: precedencia al autorizar sin stock: vigente → 409 STOCK_INSUFICIENTE; no vigente → 422 PRESCRIPCION_NO_VIGENTE; bodega desactivada y prescripción vencida → 422 VALIDACION (bodega_id); siempre sin cambios', function (array $causas, int $status, string $codigo) {
        $s = rn05EscenarioRevalidacion('solo control');
        $id = rn05CrearPendiente(rn05Auxiliar(), $s, [4]);
        $existencia = $s['lineas'][0]['existencias'][0];
        Sanctum::actingAs(rn05Regente('ajustador'));
        test()->postJson(RN05_URI_AJUSTES, [
            'existencia_id' => $existencia->id,
            'cantidad' => -18,
            'motivo' => 'Rotura de ampollas en estante',
        ])->assertSuccessful();
        expect(rn05StockDe($existencia))->toBe(2);
        foreach ($causas as $causa) {
            rn05Invalidar($causa, $s);
        }
        $antes = rn05Instantanea();

        $r = rn05Autorizar($id, rn05Regente('r2'));

        match ($codigo) {
            'STOCK_INSUFICIENTE' => rn05AfirmarInsuficiente($r, 4, 2, 0),
            'PRESCRIPCION_NO_VIGENTE' => rn05AfirmarRevalidacion($r, $causas[0], $s),
            'VALIDACION' => rn05AfirmarRevalidacion($r, 'bodega desactivada', $s),
        };
        expect($r->status())->toBe($status)
            ->and(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION')
            ->and(rn05MovimientosDe($id))->toHaveCount(0)
            ->and(rn05StockDe($existencia))->toBe(2);
    })->with([
        'vigente, sin stock' => [[], 409, 'STOCK_INSUFICIENTE'],
        'vencida, sin stock' => [['prescripción vencida'], 422, 'PRESCRIPCION_NO_VIGENTE'],
        'anulada, sin stock' => [['prescripción anulada'], 422, 'PRESCRIPCION_NO_VIGENTE'],
        'bodega desactivada + vencida, sin stock' => [['bodega desactivada', 'prescripción vencida'], 422, 'VALIDACION'],
    ]);
});

/*
|--------------------------------------------------------------------------
| S-61: al crear una pendiente se comprueba el stock vigente (sin reservarlo)
|--------------------------------------------------------------------------
|
| Crear una dispensación de control especial exige que el stock vigente alcance en ese momento
| (409 STOCK_INSUFICIENTE con los detalles de S-42 si no; nada creado y la clave libre, S-11).
| No se reserva: el stock no baja hasta autorizar, que vuelve a comprobarlo (RN-05-AC6).
| Precedencia S-54: el tope (422) antes que el stock (409).
*/

describe('RN-05 control especial: stock al crear la pendiente (S-61)', function () {
    beforeEach(function () {
        rn05Congelar();
    });

    it('RN-05-AC2 S-61 S-11: crear una pendiente sin stock vigente suficiente → 409 STOCK_INSUFICIENTE (detalles S-42) sin crear nada; tras reponer, la misma clave crea la pendiente (201, no reproducción)', function (array $lineas, array $cantidades, int $linea, array $insuficiencia) {
        $s = rn05Escenario($lineas);
        $creador = rn05Auxiliar();
        $antes = rn05Instantanea();

        $r = rn05Crear($creador, $s, $cantidades, 'rn05-s61-clave');

        rn05AfirmarInsuficiente($r, ...$insuficiencia);
        expect($r->json('error.detalles'))->toBe([
            'producto_id' => $s['lineas'][$linea]['producto']->id,
            'bodega_id' => $s['bodega']->id,
            'prescripcion_item_id' => $s['lineas'][$linea]['item']->id,
            'solicitado' => $insuficiencia[0],
            'disponible' => $insuficiencia[1],
            'vencido_no_dispensable' => $insuficiencia[2],
        ])
            ->and($r->headers->has('Idempotent-Replayed'))->toBeFalse()
            ->and(rn05Instantanea())->toBe($antes);

        // Se repone stock vigente de cada producto: la misma clave no quedó guardada (S-11).
        foreach ($s['lineas'] as $i => $l) {
            $s['lineas'][$i]['existencias'][] = rn05Lote($s['bodega'], $l['producto'], 20, 100);
        }
        $id = rn05CrearPendiente($creador, $s, $cantidades, 'rn05-s61-clave');
        expect(DB::table('dispensaciones')->where('id', $id)->value('idempotency_key'))->toBe('rn05-s61-clave');
    })->with([
        'stock vigente + 1 (pide 5 con 4)' => [[['control' => true, 'lotes' => [[4]]]], [5], 0, [5, 4, 0]],
        'sin existencias del producto' => [[['control' => true, 'lotes' => []]], [1], 0, [1, 0, 0]],
        'solo un lote que vence hoy (S-01)' => [[['control' => true, 'lotes' => [[10, 0]]]], [3], 0, [3, 0, 10]],
        'vigente insuficiente con vencido aparte' => [[['control' => true, 'lotes' => [[2, 30], [10, -1]]]], [3], 0, [3, 2, 10]],
        'mixta: el ítem sin control no alcanza' => [[['control' => true, 'lotes' => [[20]]], ['control' => false, 'lotes' => [[2]]]], [4, 3], 1, [3, 2, 0]],
    ]);

    it('RN-05-AC2 S-61 S-42: el 409 al crear una pendiente es el mismo (status, código y detalles) que el de una dispensación sin control en las mismas condiciones', function () {
        $control = rn05Escenario([['control' => true, 'lotes' => [[4], [6, 0]]]]);
        $normal = rn05Escenario([['control' => false, 'lotes' => [[4], [6, 0]]]]);
        $antes = rn05Instantanea();

        $rc = rn05Crear(rn05Auxiliar(), $control, [5]);
        $rn = rn05Crear(rn05Auxiliar(), $normal, [5]);

        rn05AfirmarInsuficiente($rc, 5, 4, 6);
        rn05AfirmarInsuficiente($rn, 5, 4, 6);
        $sinIds = fn (array $d): array => array_diff_key($d, array_flip(['producto_id', 'bodega_id', 'prescripcion_item_id']));
        expect(array_keys($rc->json('error.detalles')))->toBe(array_keys($rn->json('error.detalles')))
            ->and($sinIds($rc->json('error.detalles')))->toBe($sinIds($rn->json('error.detalles')))
            ->and($rc->json('error.mensaje'))->toBe($rn->json('error.mensaje'))
            ->and(rn05Instantanea())->toBe($antes);
    });

    it('RN-05-AC2 S-61: con stock vigente justo (cruzando lotes e ignorando uno vencido) → 201 PENDIENTE_AUTORIZACION sin descontar ni escribir kardex; luego se autoriza por el total', function (string $rol) {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[3, 30], [2, 300], [10, 0]]]]);
        $creador = $rol === 'auxiliar' ? rn05Auxiliar() : rn05Regente('r1');
        $antes = rn05Instantanea();

        $id = rn05CrearPendiente($creador, $s, [5]);

        expect(rn05Instantanea()['existencias'])->toBe($antes['existencias'])
            ->and(rn05Instantanea()['kardex'])->toBe($antes['kardex']);

        $r2 = rn05Regente('r2');
        [$e1, $e2] = $s['lineas'][0]['existencias'];
        $stocks = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($id, $r2), $id, $r2, $creador, $s, $stocks, [$e1->id => 3, $e2->id => 2]);
    })->with(['auxiliar', 'regente']);

    it('RN-05-AC2 S-61 S-54: si el tope y el stock fallan a la vez al crear una pendiente → 422 CANTIDAD_EXCEDE_PRESCRITO (el tope antes que el stock), sin cambios', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[3]]]]);
        $antes = rn05Instantanea();

        $r = rn05Crear(rn05Auxiliar(), $s, [11]);

        esperarError($r, 422, 'CANTIDAD_EXCEDE_PRESCRITO');
        expect($r->json('error.detalles.pendiente'))->toBe(10)
            ->and($r->json('error.detalles.solicitado'))->toBe(11)
            ->and(rn05Instantanea())->toBe($antes);
    });

    it('RN-05-AC2 RN-05-AC6 S-61: la comprobación no reserva: con stock 5, dos pendientes de 5 del mismo ítem → ambas 201; autorizar la primera → 200 y la segunda → 409 STOCK_INSUFICIENTE (sigue pendiente)', function () {
        $s = rn05Escenario([['control' => true, 'prescrito' => 10, 'lotes' => [[5]]]]);
        $creador = rn05Auxiliar();
        $primera = rn05CrearPendiente($creador, $s, [5]);
        $segunda = rn05CrearPendiente($creador, $s, [5]);
        $existencia = $s['lineas'][0]['existencias'][0];

        $r2 = rn05Regente('r2');
        $stocks = rn05Stocks($s);
        rn05AfirmarAutorizada(rn05Autorizar($primera, $r2), $primera, $r2, $creador, $s, $stocks, [$existencia->id => 5]);

        $antes = rn05Instantanea();
        rn05AfirmarInsuficiente(rn05Autorizar($segunda, $r2), 5, 0, 0);
        expect(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($segunda))->toBe('PENDIENTE_AUTORIZACION');

        // Y con el stock ya agotado, una tercera pendiente no se crea (S-61), aunque quede tope.
        $s2 = $s;
        $s2['lineas'][0]['item'] = PrescripcionItem::factory()->create([
            'prescripcion_id' => Prescripcion::factory()->create(['paciente_id' => $s['prescripcion']->paciente_id])->id,
            'producto_id' => $s['lineas'][0]['producto']->id,
            'cantidad' => 10,
        ]);
        $s2['prescripcion'] = Prescripcion::query()->findOrFail($s2['lineas'][0]['item']->prescripcion_id);
        rn05AfirmarInsuficiente(rn05Crear($creador, $s2, [1]), 1, 0, 0);
        expect(rn05Instantanea())->toBe($antes);
    });
});

describe('RN-05 control especial: defensa del mismo usuario en el servicio', function () {
    beforeEach(function () {
        rn05Congelar();
    });

    it('RN-05-AC4: AutorizacionDispensacion::autorizar/rechazar llamados directamente (sin la Policy de la ruta) con el creador → AutorizacionMismoUsuario; sigue pendiente sin cambios', function (string $accion) {
        $s = rn05Escenario(rn05Lineas('control + sin control'));
        $creador = rn05Regente('r1');
        $id = rn05CrearPendiente($creador, $s, rn05Composicion('control + sin control'));
        $antes = rn05Instantanea();
        $servicio = app(AutorizacionDispensacion::class);
        $dispensacion = Dispensacion::query()->findOrFail($id);

        $lanzada = null;
        try {
            $accion === 'autorizar'
                ? $servicio->autorizar($dispensacion, $creador->id)
                : $servicio->rechazar($dispensacion, $creador->id, RN05_MOTIVO);
        } catch (AutorizacionMismoUsuario $e) {
            $lanzada = $e;
        }

        expect($lanzada)->toBeInstanceOf(AutorizacionMismoUsuario::class)
            ->and($lanzada?->codigo())->toBe('AUTORIZACION_MISMO_USUARIO')
            ->and($lanzada?->status())->toBe(403)
            ->and(rn05Instantanea())->toBe($antes)
            ->and(rn05Estado($id))->toBe('PENDIENTE_AUTORIZACION');
    })->with(['autorizar', 'rechazar']);
});
