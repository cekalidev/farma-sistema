<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Enums\TipoMovimiento;
use App\Enums\TrasladoEstado;
use App\Exceptions\ErroresBaseDatos;
use App\Http\Resources\PacienteResource;
use App\Models\AuditoriaAcceso;
use App\Models\Bodega;
use App\Models\Discrepancia;
use App\Models\Existencia;
use App\Models\MovimientoKardex;
use App\Models\Paciente;
use App\Models\Prescripcion;
use App\Models\PrescripcionItem;
use App\Models\Producto;
use App\Models\Traslado;
use App\Models\User;
use App\Services\Inventario\RegistradorKardex;
use App\Services\Traslados\DatosTraslado;
use App\Services\Traslados\DespachoTraslado;
use App\Services\Traslados\ItemRecibido;
use App\Services\Traslados\ItemTraslado;
use App\Services\Traslados\RecepcionTraslado;
use App\Services\Traslados\TrasladoService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/*
 * RN-10 — Privacidad de datos del paciente (AC1..AC10).
 * Unitarios del Enmascarador: tests/Unit/RN10EnmascaradorTest.php.
 *
 * Supuestos: S-13 (admin no consulta datos clínicos de pacientes → 403), S-06/S-58/S-59/S-61
 * (flujo de autorización de control especial), S-09 (no se anula un traslado EN_TRANSITO), S-32
 * (ajuste que dejaría saldo negativo → 409), S-33 (motivo de 10 a 500), S-67/S-71 (resolver
 * discrepancia), S-74.
 *
 * Contrato que se prueba:
 *  - GET  /api/v1/pacientes/{id}                → auxiliar/regente/médico: datos completos;
 *                                                  auditor: enmascarado (AC3); admin: 403.
 *  - POST /api/v1/pacientes/buscar              → {tipo_documento?, numero_documento}; lista.
 *  - GET  /api/v1/pacientes/{id}/prescripciones → prescripciones con el paciente anidado (AC4).
 *  - GET  /api/v1/dispensaciones/{id}           → dispensación con el paciente anidado (AC4).
 *  - GET  /api/v1/auditoria                     → bitácora paginada (auditor y admin).
 *  - Tabla auditoria_accesos: usuario_id, paciente_id, accion, resultado, entidad, entidad_id,
 *    ruta, ip, correlation_id, created_at; inmutable (trigger UPDATE/DELETE/TRUNCATE).
 *  - S-80: el correlation id lo genera siempre el servidor (UUID) y viaja en X-Correlation-Id y
 *    X-Request-Id de toda respuesta y en la bitácora; el que envíe el cliente (X-Correlation-Id o
 *    X-Request-Id), si es válido, solo queda en el log como `correlation_id_cliente`.
 */

const RN10_HOY = '2026-11-05';
const RN10_DOC = '1023455678';
const RN10_NOMBRES = 'Julián Andrés';
const RN10_APELLIDOS = 'Pérez Ñáñez';
const RN10_NACIMIENTO = '1985-03-14';
const RN10_TELEFONO = '3105551234';
const RN10_CORREO = 'julian.perez@correo-sintetico.test';
const RN10_DIRECCION = 'Carrera 7 # 12-34 Apto 501';
const RN10_UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/*
|--------------------------------------------------------------------------
| Ayudantes (prefijo rn10)
|--------------------------------------------------------------------------
*/

function rn10Congelar(): void
{
    test()->travelTo(Carbon::parse(RN10_HOY.' 10:00:00', 'America/Bogota'));
}

/**
 * Paciente sintético con todos los datos personales fijados (por defecto los RN10_*).
 *
 * @param  array<string, mixed>  $sobrescribir
 */
function rn10Paciente(array $sobrescribir = []): Paciente
{
    return Paciente::factory()->create($sobrescribir + [
        'tipo_documento' => 'CC',
        'numero_documento' => RN10_DOC,
        'nombres' => RN10_NOMBRES,
        'apellidos' => RN10_APELLIDOS,
        'fecha_nacimiento' => RN10_NACIMIENTO,
        'telefono' => RN10_TELEFONO,
        'direccion' => RN10_DIRECCION,
        'correo' => RN10_CORREO,
    ]);
}

/**
 * Valores personales de un paciente que nunca pueden aparecer donde no corresponde: documento,
 * nombres y apellidos (completos y cada palabra), fecha completa, teléfono, correo (completo,
 * usuario y dominio) y dirección.
 *
 * @return list<string>
 */
function rn10ValoresSensibles(Paciente $p): array
{
    $valores = [
        $p->numero_documento,
        $p->nombres,
        $p->apellidos,
        $p->fecha_nacimiento->toDateString(),
    ];

    foreach ([$p->nombres, $p->apellidos] as $texto) {
        foreach (preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            if (mb_strlen($palabra) >= 3) {
                $valores[] = $palabra;
            }
        }
    }

    foreach ([$p->telefono, $p->direccion] as $opcional) {
        if ($opcional !== null && $opcional !== '') {
            $valores[] = $opcional;
        }
    }

    if ($p->correo !== null && $p->correo !== '') {
        $valores[] = $p->correo;
        [$usuario, $dominio] = array_pad(explode('@', $p->correo, 2), 2, '');
        foreach ([$usuario, $dominio] as $parte) {
            if (mb_strlen($parte) >= 3) {
                $valores[] = $parte;
            }
        }
    }

    return array_values(array_unique($valores));
}

/**
 * Afirma que ningún valor sensible aparece en el texto, ni tal cual ni escapado como JSON
 * (á, \/ …).
 */
function rn10SinDatosPersonales(string $texto, Paciente $p, string $donde): void
{
    foreach (rn10ValoresSensibles($p) as $valor) {
        $variantes = array_unique([
            $valor,
            trim((string) json_encode($valor), '"'),
            trim((string) json_encode($valor, JSON_UNESCAPED_SLASHES), '"'),
            trim((string) json_encode($valor, JSON_UNESCAPED_UNICODE), '"'),
        ]);
        foreach ($variantes as $variante) {
            expect(str_contains($texto, $variante))
                ->toBeFalse("{$donde} contiene el dato personal «{$valor}»");
        }
    }
}

/** Cuerpo crudo de la respuesta + su JSON re-codificado sin escapes (para buscar valores). */
function rn10CuerpoCrudo(TestResponse $r): string
{
    $crudo = (string) $r->getContent();
    $json = json_decode($crudo, true);

    return $crudo."\n".json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Todos los objetos "paciente" de un JSON (arrays con la clave numero_documento), a cualquier
 * profundidad.
 *
 * @return list<array<string, mixed>>
 */
function rn10ObjetosPaciente(mixed $json): array
{
    if (! is_array($json)) {
        return [];
    }

    $encontrados = array_key_exists('numero_documento', $json) ? [$json] : [];
    foreach ($json as $valor) {
        $encontrados = [...$encontrados, ...rn10ObjetosPaciente($valor)];
    }

    return $encontrados;
}

/** AC1: datos completos y exactos. @param array<string, mixed> $datos */
function rn10AfirmarCompleto(array $datos, Paciente $p): void
{
    expect($datos['id'] ?? null)->toBe($p->id)
        ->and($datos['tipo_documento'] ?? null)->toBe($p->tipo_documento->value)
        ->and($datos['numero_documento'] ?? null)->toBe($p->numero_documento)
        ->and($datos['nombres'] ?? null)->toBe($p->nombres)
        ->and($datos['apellidos'] ?? null)->toBe($p->apellidos)
        ->and($datos['fecha_nacimiento'] ?? null)->toBe($p->fecha_nacimiento->toDateString())
        ->and($datos)->toHaveKeys(['telefono', 'direccion', 'correo'])
        ->and($datos['telefono'])->toBe($p->telefono)
        ->and($datos['direccion'])->toBe($p->direccion)
        ->and($datos['correo'])->toBe($p->correo);
}

/** AC3: formato exacto de la spec para el paciente RN10_*. @param array<string, mixed> $datos */
function rn10AfirmarEnmascarado(array $datos, Paciente $p): void
{
    expect($datos)->toHaveKeys(['id', 'numero_documento', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono', 'direccion', 'correo'])
        ->and($datos['id'])->toBe($p->id)
        ->and($datos['numero_documento'])->toBe('******5678')
        ->and($datos['nombres'])->toBe('J*** A***')
        ->and($datos['apellidos'])->toBe('P*** Ñ***')
        ->and(is_string($datos['fecha_nacimiento']) || is_int($datos['fecha_nacimiento']))->toBeTrue()
        ->and((string) $datos['fecha_nacimiento'])->toBe('1985')
        ->and($datos['telefono'])->toBe('*******234')
        ->and($datos['correo'])->toBe('j***@***')
        ->and($datos['direccion'])->toBeNull();
}

/** @return list<object> filas de la bitácora en orden de inserción */
function rn10Filas(): array
{
    return DB::table('auditoria_accesos')->orderBy('id')->get()->all();
}

function rn10Fecha(object $fila): Carbon
{
    $valor = $fila->created_at ?? null;
    expect($valor)->not->toBeNull('La fila de la bitácora no tiene fecha (created_at)');

    return Carbon::parse((string) $valor);
}

/** Afirma las columnas comunes de una fila de acceso (AC5/AC6). */
function rn10AfirmarFilaAcceso(object $fila, User $usuario, ?int $pacienteId, string $resultado, TestResponse $r, string $ip = '127.0.0.1'): void
{
    $correlacion = $r->headers->get('X-Correlation-Id');

    expect($fila->usuario_id)->toBe($usuario->id)
        ->and($fila->resultado)->toBe($resultado)
        ->and($fila->accion)->toBeString()->not->toBe('')
        ->and($fila->ruta)->toBeString()->toContain('pacientes')
        ->and($fila->ip)->toBe($ip)
        ->and($correlacion)->toBeString()->not->toBe('')
        ->and($fila->correlation_id)->toBe($correlacion)
        ->and(rn10Fecha($fila)->getTimestamp())->toBe(now()->getTimestamp());

    if ($pacienteId !== null) {
        expect($fila->paciente_id)->toBe($pacienteId);
    }
}

/** Afirma que una fila de la bitácora no contiene datos personales del paciente. */
function rn10FilaSinDatosPersonales(object $fila, Paciente $p): void
{
    rn10SinDatosPersonales((string) json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $p, "La fila {$fila->id} de auditoria_accesos");
}

/** Existencia vigente con `cantidad` unidades registradas como ENTRADA en el kardex. */
function rn10ExistenciaConStock(int $cantidad): Existencia
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
 * Dispensación creada por HTTP por un auxiliar nuevo (CONFIRMADA o, si es de control especial,
 * PENDIENTE_AUTORIZACION). Deja la autenticación olvidada para el siguiente actor.
 *
 * @return array{id: int, paciente: Paciente, prescripcion: Prescripcion, existencia: Existencia, auxiliar: User}
 */
function rn10Dispensacion(Paciente $paciente, bool $controlEspecial, int $stock = 20, int $cantidad = 3): array
{
    $existencia = rn10ExistenciaConStock($stock);
    if ($controlEspecial) {
        Producto::query()->whereKey($existencia->producto_id)->update(['control_especial' => true]);
    }
    $prescripcion = Prescripcion::factory()->create(['paciente_id' => $paciente->id]);
    $item = PrescripcionItem::factory()->create([
        'prescripcion_id' => $prescripcion->id,
        'producto_id' => $existencia->producto_id,
        'cantidad' => 30,
    ]);

    $auxiliar = actuarComo('auxiliar_farmacia');
    $r = test()->postJson('/api/v1/dispensaciones', [
        'paciente_id' => $paciente->id,
        'prescripcion_id' => $prescripcion->id,
        'bodega_id' => $existencia->bodega_id,
        'items' => [['prescripcion_item_id' => $item->id, 'cantidad' => $cantidad]],
    ], cabecerasDispensacion());
    $r->assertCreated();
    expect($r->json('data.estado'))->toBe($controlEspecial ? 'PENDIENTE_AUTORIZACION' : 'CONFIRMADA');
    olvidarAutenticacion();

    return [
        'id' => (int) $r->json('data.id'),
        'paciente' => $paciente,
        'prescripcion' => $prescripcion,
        'existencia' => $existencia->refresh(),
        'auxiliar' => $auxiliar,
    ];
}

/**
 * Traslado de 5 u. (origen con 20) llevado por los servicios reales hasta `$hasta`. Lo crea y
 * solicita `$creador`; aprueba, despacha y recibe (3 de 5 en RECIBIDO_PARCIAL) otro regente.
 */
function rn10Traslado(TrasladoEstado $hasta, User $creador): Traslado
{
    $existencia = rn10ExistenciaConStock(20);
    $regente = User::factory()->create(['rol' => Rol::RegenteFarmacia])->id;
    $servicio = app(TrasladoService::class);

    $traslado = $servicio->crear(new DatosTraslado(
        usuarioId: $creador->id,
        bodegaOrigenId: $existencia->bodega_id,
        bodegaDestinoId: Bodega::factory()->create()->id,
        observaciones: null,
        items: [new ItemTraslado($existencia->producto_id, $existencia->lote_id, 5)],
    ));

    $pasos = [
        TrasladoEstado::Solicitado->value => fn (Traslado $t): Traslado => $servicio->solicitar($t, $creador->id),
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

/**
 * Envía TODOS los canales de log con archivo (single, daily…) a un archivo temporal propio,
 * conservando el resto de su configuración (procesadores, taps), y olvida los canales resueltos.
 */
function rn10CapturarLog(): string
{
    $ruta = (string) tempnam(sys_get_temp_dir(), 'farma-rn10-log-');
    $canales = (array) config('logging.channels');

    foreach ($canales as $nombre => $canal) {
        if (is_array($canal) && array_key_exists('path', $canal)) {
            config(["logging.channels.{$nombre}.path" => $ruta]);
        }
    }
    foreach (array_keys($canales) as $nombre) {
        Log::forgetChannel((string) $nombre);
    }
    Log::forgetChannel();

    $GLOBALS['rn10Logs'][] = $ruta;

    return $ruta;
}

/** Contenido del log capturado (incluye archivos derivados, p. ej. el sufijo de fecha de daily). */
function rn10LeerLog(string $ruta): string
{
    $contenido = '';
    foreach (glob($ruta.'*') ?: [] as $archivo) {
        $contenido .= (string) file_get_contents($archivo);
    }

    return $contenido;
}

/** SQLSTATE del error de BD de la operación (en un savepoint), o null si no falló. */
function rn10Sqlstate(Closure $operacion): ?string
{
    try {
        DB::transaction(fn () => $operacion());
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

/**
 * Filas nuevas de operación (con entidad) desde `$antes`.
 *
 * @return list<object>
 */
function rn10FilasOperacion(int $antes): array
{
    return array_values(array_filter(
        array_slice(rn10Filas(), $antes),
        fn (object $f): bool => $f->entidad !== null,
    ));
}

function rn10AfirmarOperacion(int $antes, User $usuario, string $entidad, int $entidadId): object
{
    $filas = rn10FilasOperacion($antes);
    expect($filas)->toHaveCount(1);
    $fila = $filas[0];
    expect($fila->usuario_id)->toBe($usuario->id)
        ->and($fila->accion)->toBeString()->not->toBe('')
        ->and($fila->entidad)->toBe($entidad)
        ->and($fila->entidad_id)->toBe($entidadId)
        ->and(rn10Fecha($fila)->getTimestamp())->toBe(now()->getTimestamp());

    return $fila;
}

/** @return array<int, mixed> estados de dispensaciones, kardex, stock, traslados y discrepancias */
function rn10EstadoNegocio(): array
{
    return [
        DB::table('dispensaciones')->orderBy('id')->pluck('estado')->all(),
        MovimientoKardex::query()->count(),
        (int) Existencia::query()->sum('cantidad'),
        Traslado::query()->orderBy('id')->pluck('estado')->map(fn ($e) => $e->value)->all(),
        Discrepancia::query()->orderBy('id')->pluck('estado')->map(fn ($e) => $e->value)->all(),
    ];
}

/**
 * Copia completa (todas las columnas) de las tablas de negocio que tocan las operaciones sensibles
 * y de la bitácora.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function rn10Instantanea(): array
{
    $tablas = ['existencias', 'movimientos_kardex', 'dispensaciones', 'traslados', 'traslado_items', 'discrepancias', 'auditoria_accesos'];
    $copia = [];
    foreach ($tablas as $tabla) {
        $copia[$tabla] = DB::table($tabla)->orderBy('id')->get()->map(fn (object $fila): array => (array) $fila)->all();
    }

    return $copia;
}

/** Mensaje fijo del trigger temporal que hace fallar cada INSERT en la bitácora (S-81). */
const RN10_FALLO_BITACORA = 'rn10 fallo forzado al escribir la bitacora';

/**
 * S-81: trigger temporal BEFORE INSERT sobre auditoria_accesos que lanza un error. Se crea dentro de
 * la transacción de RefreshDatabase (el DDL de PostgreSQL es transaccional), así que desaparece al
 * revertirla al final del test.
 */
function rn10HacerFallarLaBitacora(): void
{
    DB::unprepared("
        CREATE FUNCTION rn10_bitacora_falla() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            RAISE EXCEPTION '".RN10_FALLO_BITACORA."';
        END
        \$\$;
        CREATE TRIGGER rn10_bitacora_falla BEFORE INSERT ON auditoria_accesos
            FOR EACH ROW EXECUTE FUNCTION rn10_bitacora_falla();
    ");
}

/**
 * ¿La transacción del test sigue usable? Si una escritura falló FUERA de la transacción (savepoint)
 * de la operación, PostgreSQL deja abortada la transacción exterior (25P02) y la operación ya se
 * había confirmado en ella: es la señal de que la bitácora no se escribió en la misma transacción.
 */
function rn10TransaccionSana(): bool
{
    try {
        DB::select('SELECT 1');
    } catch (QueryException) {
        return false;
    }

    return true;
}

afterEach(function () {
    foreach ($GLOBALS['rn10Logs'] ?? [] as $ruta) {
        foreach (glob($ruta.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
    }
    $GLOBALS['rn10Logs'] = [];
});

/*
|--------------------------------------------------------------------------
| AC1–AC3: acceso por rol y enmascarado
|--------------------------------------------------------------------------
*/

describe('RN-10 acceso por rol y enmascarado del detalle de paciente', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC1: auxiliar, regente y médico reciben 200 con todos los datos personales exactos', function (string $rol) {
        actuarComo($rol);

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        rn10AfirmarCompleto(cuerpoDatos($r), $this->paciente);
    })->with(['auxiliar_farmacia', 'regente_farmacia', 'medico']);

    it('RN-10-AC1: datos opcionales nulos se devuelven como null (sin inventar valores)', function () {
        $otro = rn10Paciente(['numero_documento' => '8800112233', 'telefono' => null, 'correo' => null, 'direccion' => null]);
        actuarComo('regente_farmacia');

        $r = $this->getJson("/api/v1/pacientes/{$otro->id}");

        $r->assertOk();
        rn10AfirmarCompleto(cuerpoDatos($r), $otro);
    });

    it('RN-10-AC2 RN-10-AC6: admin recibe 403 NO_AUTORIZADO sin datos del paciente y queda una fila DENEGADO', function () {
        $admin = actuarComo('admin');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        esperarError($r, 403, 'NO_AUTORIZADO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 403');

        $filas = rn10Filas();
        expect($filas)->toHaveCount(1);
        rn10AfirmarFilaAcceso($filas[0], $admin, null, 'DENEGADO', $r);
        expect($filas[0]->paciente_id)->toBeIn([null, $this->paciente->id]);
        rn10FilaSinDatosPersonales($filas[0], $this->paciente);
    });

    it('RN-10-AC3: auditor recibe 200 con el formato exacto de la spec en cada campo', function () {
        actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        $datos = cuerpoDatos($r);
        rn10AfirmarEnmascarado($datos, $this->paciente);
        expect($datos['tipo_documento'] ?? null)->toBe('CC');
    });

    it('RN-10-AC3: ningún valor personal real aparece en el cuerpo crudo de la respuesta del auditor', function () {
        actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta del auditor');
    });

    it('RN-10-AC3: datos opcionales nulos del paciente siguen null para el auditor', function () {
        $otro = rn10Paciente(['numero_documento' => '8800112233', 'telefono' => null, 'correo' => null, 'direccion' => null]);
        actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$otro->id}");

        $r->assertOk();
        $datos = cuerpoDatos($r);
        expect($datos['numero_documento'])->toBe('******2233')
            ->and($datos['telefono'])->toBeNull()
            ->and($datos['correo'])->toBeNull()
            ->and($datos['direccion'])->toBeNull();
    });

    it('RN-10-AC3: documento corto del paciente no se revela al auditor', function () {
        $corto = rn10Paciente(['numero_documento' => '913', 'tipo_documento' => 'RC']);
        actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$corto->id}");

        $r->assertOk();
        expect(cuerpoDatos($r)['numero_documento'])->toMatch('/^\*+$/');
    });

    it('RN-10-AC1 RN-10-AC3: sin token → 401 NO_AUTENTICADO y no se registra nada', function () {
        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        esperarError($r, 401, 'NO_AUTENTICADO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 401');
        expect(rn10Filas())->toBe([]);
    });

    it('RN-10-AC5: un paciente inexistente → 404 NO_ENCONTRADO y ninguna fila PERMITIDO', function (string $rol) {
        actuarComo($rol);

        esperarError($this->getJson('/api/v1/pacientes/'.($this->paciente->id + 999)), 404, 'NO_ENCONTRADO');

        expect(DB::table('auditoria_accesos')->where('resultado', 'PERMITIDO')->count())->toBe(0);
    })->with(['regente_farmacia', 'auditor']);
});

/*
|--------------------------------------------------------------------------
| AC4: recursos que anidan al paciente
|--------------------------------------------------------------------------
*/

describe('RN-10-AC4 paciente anidado en prescripciones y dispensaciones', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC4: auditor en GET /pacientes/{id}/prescripciones → el paciente anidado sale enmascarado igual que en AC3', function () {
        Prescripcion::factory()->count(2)->create(['paciente_id' => $this->paciente->id]);
        Prescripcion::factory()->create(['paciente_id' => Paciente::factory()->create()->id]);
        actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");

        $r->assertOk();
        expect($r->json('data'))->toBeArray()->toHaveCount(2);
        $objetos = rn10ObjetosPaciente($r->json());
        expect($objetos)->not->toBe([]);
        foreach ($objetos as $objeto) {
            rn10AfirmarEnmascarado($objeto, $this->paciente);
        }
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'Las prescripciones vistas por el auditor');
    });

    it('RN-10-AC4: auxiliar, regente y médico ven el paciente anidado en prescripciones con datos completos', function (string $rol) {
        Prescripcion::factory()->count(2)->create(['paciente_id' => $this->paciente->id]);
        actuarComo($rol);

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");

        $r->assertOk();
        expect($r->json('data'))->toHaveCount(2);
        $objetos = rn10ObjetosPaciente($r->json());
        expect($objetos)->not->toBe([]);
        foreach ($objetos as $objeto) {
            rn10AfirmarCompleto($objeto, $this->paciente);
        }
    })->with(['auxiliar_farmacia', 'regente_farmacia', 'medico']);

    it('RN-10-AC2 RN-10-AC6: admin en GET /pacientes/{id}/prescripciones → 403 y fila DENEGADO sin datos personales', function () {
        Prescripcion::factory()->create(['paciente_id' => $this->paciente->id]);
        $admin = actuarComo('admin');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");

        esperarError($r, 403, 'NO_AUTORIZADO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 403');
        $filas = rn10Filas();
        expect($filas)->toHaveCount(1);
        rn10AfirmarFilaAcceso($filas[0], $admin, null, 'DENEGADO', $r);
        rn10FilaSinDatosPersonales($filas[0], $this->paciente);
    });

    it('RN-10-AC4 RN-10-AC5: auditor en GET /dispensaciones/{id} → paciente anidado enmascarado y una fila PERMITIDO del paciente', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: false);
        $antes = count(rn10Filas());
        $auditor = actuarComo('auditor');

        $r = $this->getJson("/api/v1/dispensaciones/{$disp['id']}");

        $r->assertOk();
        $objetos = rn10ObjetosPaciente($r->json());
        expect($objetos)->not->toBe([]);
        foreach ($objetos as $objeto) {
            rn10AfirmarEnmascarado($objeto, $this->paciente);
        }
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La dispensación vista por el auditor');

        $nuevas = array_slice(rn10Filas(), $antes);
        expect($nuevas)->toHaveCount(1);
        expect($nuevas[0]->usuario_id)->toBe($auditor->id)
            ->and($nuevas[0]->paciente_id)->toBe($this->paciente->id)
            ->and($nuevas[0]->resultado)->toBe('PERMITIDO')
            ->and($nuevas[0]->correlation_id)->toBe($r->headers->get('X-Correlation-Id'));
    });

    it('RN-10-AC4 S-79: el auditor recibe null en observaciones e indicaciones (texto clínico) y sus valores no aparecen en el cuerpo; auxiliar y regente los ven', function (string $rol, bool $enmascarado) {
        $observaciones = 'Observación clínica sintética RN10 hipertensión controlada';
        $indicaciones = ['Indicación sintética RN10 tomar con alimentos', 'Indicación sintética RN10 suspender si mareo'];
        $prescripcion = Prescripcion::factory()->create(['paciente_id' => $this->paciente->id, 'observaciones' => $observaciones]);
        foreach ($indicaciones as $texto) {
            PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'indicaciones' => $texto]);
        }
        actuarComo($rol);

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");

        $r->assertOk();
        expect($r->json('data'))->toHaveCount(1)
            ->and($r->json('data.0'))->toHaveKey('observaciones')
            ->and($r->json('data.0.items'))->toHaveCount(2);
        $crudo = rn10CuerpoCrudo($r);
        if ($enmascarado) {
            expect($r->json('data.0.observaciones'))->toBeNull()
                ->and(array_column($r->json('data.0.items'), 'indicaciones'))->toBe([null, null])
                ->and($crudo)->not->toContain($observaciones)
                ->and($crudo)->not->toContain('RN10');
            foreach ($indicaciones as $texto) {
                expect($crudo)->not->toContain($texto);
            }
        } else {
            expect($r->json('data.0.observaciones'))->toBe($observaciones)
                ->and(array_column($r->json('data.0.items'), 'indicaciones'))->toBe($indicaciones);
        }
    })->with([
        'auditor' => ['auditor', true],
        'auxiliar' => ['auxiliar_farmacia', false],
        'regente' => ['regente_farmacia', false],
    ]);

    it('RN-10-AC6 S-77: médico en GET /dispensaciones/{id} → 403 y una fila DENEGADO con el paciente, la entidad y el id de la dispensación, sin datos personales', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: false);
        $antes = count(rn10Filas());
        $medico = actuarComo('medico');

        $r = $this->getJson("/api/v1/dispensaciones/{$disp['id']}");

        esperarError($r, 403, 'NO_AUTORIZADO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 403 al médico');
        $nuevas = array_slice(rn10Filas(), $antes);
        expect($nuevas)->toHaveCount(1);
        $fila = $nuevas[0];
        expect($fila->usuario_id)->toBe($medico->id)
            ->and($fila->resultado)->toBe('DENEGADO')
            ->and($fila->paciente_id)->toBe($this->paciente->id)
            ->and($fila->entidad)->toBe('dispensacion')
            ->and($fila->entidad_id)->toBe($disp['id'])
            ->and($fila->accion)->toBeString()->not->toBe('')
            ->and($fila->ruta)->toBe("/api/v1/dispensaciones/{$disp['id']}")
            ->and($fila->correlation_id)->toBe($r->headers->get('X-Correlation-Id'))
            ->and(rn10Fecha($fila)->getTimestamp())->toBe(now()->getTimestamp());
        rn10FilaSinDatosPersonales($fila, $this->paciente);
    });

    it('RN-10-AC4: regente en GET /dispensaciones/{id} ve el paciente anidado completo', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: false);
        actuarComo('regente_farmacia');

        $r = $this->getJson("/api/v1/dispensaciones/{$disp['id']}");

        $r->assertOk();
        $objetos = rn10ObjetosPaciente($r->json());
        expect($objetos)->not->toBe([]);
        foreach ($objetos as $objeto) {
            rn10AfirmarCompleto($objeto, $this->paciente);
        }
    });
});

/*
|--------------------------------------------------------------------------
| AC5–AC6: bitácora de accesos
|--------------------------------------------------------------------------
*/

describe('RN-10-AC5 RN-10-AC6 bitácora de accesos a pacientes', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC5: cada consulta exitosa del detalle inserta UNA fila PERMITIDO con usuario, paciente, acción, ruta, ip, correlation_id y fecha', function (string $rol) {
        $usuario = actuarComo($rol);

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        $filas = rn10Filas();
        expect($filas)->toHaveCount(1);
        rn10AfirmarFilaAcceso($filas[0], $usuario, $this->paciente->id, 'PERMITIDO', $r);
        rn10FilaSinDatosPersonales($filas[0], $this->paciente);
    })->with(['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor']);

    it('RN-10-AC5: dos consultas → dos filas, cada una con su propio correlation_id', function () {
        $usuario = actuarComo('regente_farmacia');

        $r1 = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");
        $r2 = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $filas = rn10Filas();
        expect($filas)->toHaveCount(2);
        rn10AfirmarFilaAcceso($filas[0], $usuario, $this->paciente->id, 'PERMITIDO', $r1);
        rn10AfirmarFilaAcceso($filas[1], $usuario, $this->paciente->id, 'PERMITIDO', $r2);
        expect($filas[0]->correlation_id)->not->toBe($filas[1]->correlation_id)
            ->and($filas[0]->accion)->toBe($filas[1]->accion);
    });

    it('RN-10-AC5: la ip registrada es la de la petición', function () {
        $usuario = actuarComo('medico');

        $r = $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        rn10AfirmarFilaAcceso(rn10Filas()[0], $usuario, $this->paciente->id, 'PERMITIDO', $r, '10.20.30.40');
    });

    it('RN-10-AC5: la consulta de prescripciones del paciente (anidado) inserta UNA fila PERMITIDO de ese paciente', function () {
        Prescripcion::factory()->count(3)->create(['paciente_id' => $this->paciente->id]);
        $usuario = actuarComo('auditor');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");

        $r->assertOk();
        $filas = rn10Filas();
        expect($filas)->toHaveCount(1);
        rn10AfirmarFilaAcceso($filas[0], $usuario, $this->paciente->id, 'PERMITIDO', $r);
        rn10FilaSinDatosPersonales($filas[0], $this->paciente);
    });

    it('RN-10-AC5: la búsqueda inserta una fila PERMITIDO por cada paciente devuelto', function (?string $tipo, int $esperados) {
        // Mismo número en tres tipos de documento (UNIQUE es tipo + número).
        $ti = rn10Paciente(['tipo_documento' => 'TI', 'nombres' => 'Valentina', 'apellidos' => 'Rojas Gil']);
        $ce = rn10Paciente(['tipo_documento' => 'CE', 'nombres' => 'Tomás', 'apellidos' => 'Lugo Mena']);
        rn10Paciente(['numero_documento' => '7700112233']); // otro número: nunca aparece
        $usuario = actuarComo('auxiliar_farmacia');
        $cuerpo = ['numero_documento' => RN10_DOC] + ($tipo === null ? [] : ['tipo_documento' => $tipo]);

        $r = $this->postJson('/api/v1/pacientes/buscar', $cuerpo);

        $r->assertOk();
        $ids = array_map(fn (array $p): int => $p['id'], $r->json('data'));
        expect($ids)->toHaveCount($esperados);
        $esperadosIds = match ($tipo) {
            null => [$this->paciente->id, $ti->id, $ce->id],
            'TI' => [$ti->id],
            default => [$this->paciente->id],
        };
        expect($ids)->toEqualCanonicalizing($esperadosIds);

        $filas = rn10Filas();
        expect($filas)->toHaveCount($esperados)
            ->and(array_map(fn (object $f): int => $f->paciente_id, $filas))->toEqualCanonicalizing($esperadosIds);
        foreach ($filas as $fila) {
            rn10AfirmarFilaAcceso($fila, $usuario, null, 'PERMITIDO', $r);
            rn10FilaSinDatosPersonales($fila, $this->paciente);
        }
    })->with([
        'sin tipo: 3 coincidencias' => [null, 3],
        'con tipo CC: 1' => ['CC', 1],
        'con tipo TI: 1' => ['TI', 1],
    ]);

    it('RN-10-AC5: búsqueda sin resultados → 200 lista vacía y 0 filas', function (array $cuerpo) {
        actuarComo('regente_farmacia');

        $r = $this->postJson('/api/v1/pacientes/buscar', $cuerpo);

        $r->assertOk();
        expect($r->json('data'))->toBe([])
            ->and(rn10Filas())->toBe([]);
    })->with([
        'número inexistente' => [['numero_documento' => '5550001112']],
        'tipo que no coincide' => [['numero_documento' => RN10_DOC, 'tipo_documento' => 'PA']],
        'prefijo del documento (no hay coincidencia parcial)' => [['numero_documento' => '10234']],
        'sufijo del documento' => [['numero_documento' => '5678']],
    ]);

    it('RN-10-AC5 RN-10-AC3: la búsqueda del auditor devuelve los pacientes enmascarados y sin datos reales en el cuerpo', function () {
        $auditor = actuarComo('auditor');

        $r = $this->postJson('/api/v1/pacientes/buscar', ['tipo_documento' => 'CC', 'numero_documento' => RN10_DOC]);

        $r->assertOk();
        expect($r->json('data'))->toHaveCount(1);
        rn10AfirmarEnmascarado($r->json('data.0'), $this->paciente);
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La búsqueda del auditor');
        rn10AfirmarFilaAcceso(rn10Filas()[0], $auditor, $this->paciente->id, 'PERMITIDO', $r);
    });

    it('RN-10-AC5: la búsqueda con datos inválidos → 422 VALIDACION y ninguna fila', function (array $cuerpo) {
        actuarComo('regente_farmacia');

        esperarError($this->postJson('/api/v1/pacientes/buscar', $cuerpo), 422, 'VALIDACION');

        expect(rn10Filas())->toBe([]);
    })->with([
        'sin documento' => [['tipo_documento' => 'CC']],
        'documento vacío' => [['numero_documento' => '']],
        'documento no texto' => [['numero_documento' => ['1023455678']]],
        'tipo inexistente' => [['tipo_documento' => 'ZZ', 'numero_documento' => RN10_DOC]],
    ]);

    it('RN-10-AC2 RN-10-AC6: admin en la búsqueda → 403 y una fila DENEGADO sin el documento buscado', function () {
        $admin = actuarComo('admin');

        $r = $this->postJson('/api/v1/pacientes/buscar', ['tipo_documento' => 'CC', 'numero_documento' => RN10_DOC]);

        esperarError($r, 403, 'NO_AUTORIZADO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 403 de la búsqueda');
        $filas = rn10Filas();
        expect($filas)->toHaveCount(1);
        rn10AfirmarFilaAcceso($filas[0], $admin, null, 'DENEGADO', $r);
        rn10FilaSinDatosPersonales($filas[0], $this->paciente);
    });

    it('RN-10-AC6: los intentos denegados no generan filas PERMITIDO', function () {
        actuarComo('admin');

        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertForbidden();
        $this->postJson('/api/v1/pacientes/buscar', ['numero_documento' => RN10_DOC])->assertForbidden();
        $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones")->assertForbidden();

        expect(DB::table('auditoria_accesos')->where('resultado', 'PERMITIDO')->count())->toBe(0)
            ->and(DB::table('auditoria_accesos')->where('resultado', 'DENEGADO')->count())->toBe(3);
    });
});

/*
|--------------------------------------------------------------------------
| Correlation id (AC5: correlation_id de la fila = cabecera de la respuesta)
|--------------------------------------------------------------------------
*/

describe('RN-10-AC5 correlation id', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC5 S-80: sin cabecera del cliente se genera un UUID que viaja en X-Correlation-Id, X-Request-Id y en la fila', function () {
        actuarComo('regente_farmacia');

        $r = $this->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        $correlacion = $r->headers->get('X-Correlation-Id');
        expect($correlacion)->toMatch(RN10_UUID)
            ->and($r->headers->get('X-Request-Id'))->toBe($correlacion)
            ->and(rn10Filas()[0]->correlation_id)->toBe($correlacion);
    });

    it('RN-10-AC5 S-80: con un id válido del cliente, la respuesta y la fila llevan un UUID propio del servidor y el del cliente no llega a la bitácora', function (string $cabecera) {
        actuarComo('regente_farmacia');
        $enviado = 'cliente-'.Str::lower((string) Str::ulid());

        $r = $this->withHeaders([$cabecera => $enviado])->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        $correlacion = $r->headers->get('X-Correlation-Id');
        $filas = rn10Filas();
        expect($correlacion)->toMatch(RN10_UUID)
            ->and($correlacion)->not->toBe($enviado)
            ->and($r->headers->get('X-Request-Id'))->toBe($correlacion)
            ->and($filas)->toHaveCount(1)
            ->and($filas[0]->correlation_id)->toBe($correlacion)
            ->and(DB::table('auditoria_accesos')->where('correlation_id', $enviado)->exists())->toBeFalse()
            ->and((string) json_encode($filas))->not->toContain($enviado)
            ->and((string) $r->getContent())->not->toContain($enviado);
    })->with(['X-Correlation-Id', 'X-Request-Id']);

    it('RN-10-AC5 S-80: el id válido del cliente queda en el log solo como dato informativo, junto al id del servidor', function (string $cabecera) {
        $log = rn10CapturarLog();
        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/prueba-rn10-correlacion-log/{paciente}', function (Paciente $paciente): array {
                Log::info('Prueba RN-10 de correlación', ['paciente_id' => $paciente->id]);

                return ['ok' => true];
            });
        actuarComo('regente_farmacia');
        $enviado = 'cliente-'.Str::lower((string) Str::ulid());

        $r = $this->withHeaders([$cabecera => $enviado])->getJson("/api/v1/prueba-rn10-correlacion-log/{$this->paciente->id}");

        $r->assertOk();
        $correlacion = (string) $r->headers->get('X-Correlation-Id');
        expect($correlacion)->toMatch(RN10_UUID)
            ->and($correlacion)->not->toBe($enviado)
            ->and($r->headers->get('X-Request-Id'))->toBe($correlacion);
        $contenido = rn10LeerLog($log);
        expect($contenido)->toContain('Prueba RN-10 de correlación')
            ->and($contenido)->toContain('"correlation_id":"'.$correlacion.'"')
            ->and($contenido)->toContain('"correlation_id_cliente":"'.$enviado.'"');
    })->with(['X-Correlation-Id', 'X-Request-Id']);

    it('RN-10-AC5 S-80: sin id del cliente en una petición, el log no arrastra el de una petición anterior', function () {
        $log = rn10CapturarLog();
        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/prueba-rn10-correlacion-log', function (): array {
                Log::info('Prueba RN-10 sin id del cliente');

                return ['ok' => true];
            });
        actuarComo('regente_farmacia');
        $enviado = 'cliente-'.Str::lower((string) Str::ulid());
        $this->withHeaders(['X-Correlation-Id' => $enviado])->getJson('/api/v1/prueba-rn10-correlacion-log')->assertOk();
        file_put_contents($log, '');

        $r = $this->withHeaders(['X-Correlation-Id' => '', 'X-Request-Id' => ''])->getJson('/api/v1/prueba-rn10-correlacion-log');

        $r->assertOk();
        $contenido = rn10LeerLog($log);
        expect($contenido)->toContain('Prueba RN-10 sin id del cliente')
            ->and($contenido)->toContain((string) $r->headers->get('X-Correlation-Id'))
            ->and($contenido)->not->toContain($enviado)
            ->and($contenido)->not->toContain('correlation_id_cliente');
    });

    it('RN-10-AC5: un correlation id inválido o demasiado largo se descarta y se genera un UUID nuevo', function (string $cabecera, string $enviado) {
        actuarComo('regente_farmacia');

        $r = $this->withHeaders([$cabecera => $enviado])->getJson("/api/v1/pacientes/{$this->paciente->id}");

        $r->assertOk();
        $correlacion = $r->headers->get('X-Correlation-Id');
        expect($correlacion)->toMatch(RN10_UUID)
            ->and($correlacion)->not->toBe($enviado)
            ->and($r->headers->get('X-Request-Id'))->toBe($correlacion)
            ->and(rn10Filas()[0]->correlation_id)->toBe($correlacion);
    })->with([
        'demasiado largo' => ['X-Correlation-Id', str_repeat('a', 300)],
        'demasiado largo (X-Request-Id)' => ['X-Request-Id', str_repeat('7', 256)],
        'con espacios' => ['X-Correlation-Id', 'id con espacios 123'],
        'inyección HTML' => ['X-Correlation-Id', '<script>alert(1)</script>'],
        'punto y coma y comillas' => ['X-Request-Id', "abc123';DROP TABLE x;--"],
        'no ASCII' => ['X-Correlation-Id', 'ñandú-12345678'],
        'documento del paciente con espacios' => ['X-Correlation-Id', 'CC '.RN10_DOC],
    ]);

    it('RN-10-AC6 S-80: la fila DENEGADO lleva el correlation id del servidor de la respuesta 403, no el enviado por el cliente', function () {
        actuarComo('admin');
        $enviado = (string) Str::uuid();

        $r = $this->withHeaders(['X-Correlation-Id' => $enviado])->getJson("/api/v1/pacientes/{$this->paciente->id}");

        esperarError($r, 403, 'NO_AUTORIZADO');
        $correlacion = $r->headers->get('X-Correlation-Id');
        expect($correlacion)->toMatch(RN10_UUID)
            ->and($correlacion)->not->toBe($enviado)
            ->and($r->headers->get('X-Request-Id'))->toBe($correlacion)
            ->and(rn10Filas()[0]->correlation_id)->toBe($correlacion)
            ->and(DB::table('auditoria_accesos')->where('correlation_id', $enviado)->exists())->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| AC7: búsqueda por POST; el documento no viaja en la URL
|--------------------------------------------------------------------------
*/

describe('RN-10-AC7 búsqueda por documento solo por POST', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC7: no hay búsqueda por GET con el documento en la query (404/405, sin datos ni filas PERMITIDO)', function (string $uri) {
        actuarComo('regente_farmacia');

        $r = $this->getJson($uri);

        expect($r->status())->toBeIn([404, 405]);
        esperarError($r, $r->status(), $r->status() === 404 ? 'NO_ENCONTRADO' : 'METODO_NO_PERMITIDO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, "La respuesta de GET {$uri}");
        expect(DB::table('auditoria_accesos')->where('resultado', 'PERMITIDO')->count())->toBe(0);
    })->with([
        'GET /pacientes/buscar?numero_documento' => ['/api/v1/pacientes/buscar?numero_documento='.RN10_DOC],
        'GET /pacientes/buscar?tipo&numero' => ['/api/v1/pacientes/buscar?tipo_documento=CC&numero_documento='.RN10_DOC],
        'GET /pacientes?numero_documento' => ['/api/v1/pacientes?numero_documento='.RN10_DOC],
        'GET /pacientes/{documento}' => ['/api/v1/pacientes/'.RN10_DOC],
    ]);

    it('RN-10-AC7: POST /pacientes/buscar con el documento solo en la query string no busca (422 VALIDACION, sin filas)', function () {
        actuarComo('regente_farmacia');

        $r = $this->postJson('/api/v1/pacientes/buscar?numero_documento='.RN10_DOC.'&tipo_documento=CC', []);

        esperarError($r, 422, 'VALIDACION');
        expect(rn10Filas())->toBe([]);
    });

    it('RN-10-AC7: la ruta registrada en la bitácora de la búsqueda no contiene el documento', function () {
        actuarComo('regente_farmacia');

        $r = $this->postJson('/api/v1/pacientes/buscar', ['tipo_documento' => 'CC', 'numero_documento' => RN10_DOC]);

        $r->assertOk();
        $fila = rn10Filas()[0];
        expect($fila->ruta)->toContain('pacientes/buscar')
            ->and($fila->ruta)->not->toContain(RN10_DOC)
            ->and($fila->ruta)->not->toContain('?');
        rn10FilaSinDatosPersonales($fila, $this->paciente);
    });

    it('RN-10-AC7: la ruta del detalle registrada solo lleva el id del paciente', function () {
        actuarComo('auxiliar_farmacia');

        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();

        $fila = rn10Filas()[0];
        expect($fila->ruta)->toMatch('#pacientes/(\{[a-z_]+\}|'.$this->paciente->id.')$#');
        rn10FilaSinDatosPersonales($fila, $this->paciente);
    });
});

/*
|--------------------------------------------------------------------------
| AC8: ningún dato personal en los logs
|--------------------------------------------------------------------------
*/

describe('RN-10-AC8 logs sin datos personales', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
        $this->log = rn10CapturarLog();
    });

    it('RN-10-AC8: consultas (detalle, búsqueda, prescripciones) de cualquier rol no dejan datos personales en el log', function () {
        Prescripcion::factory()->create(['paciente_id' => $this->paciente->id]);

        foreach (['auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'] as $rol) {
            actuarComo($rol);
            $this->getJson("/api/v1/pacientes/{$this->paciente->id}");
            $this->postJson('/api/v1/pacientes/buscar', ['tipo_documento' => 'CC', 'numero_documento' => RN10_DOC]);
            $this->getJson("/api/v1/pacientes/{$this->paciente->id}/prescripciones");
            olvidarAutenticacion();
        }

        rn10SinDatosPersonales(rn10LeerLog($this->log), $this->paciente, 'El log');
    });

    it('RN-10-AC8: una dispensación (creación y consulta) no deja datos personales en el log', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: false);
        actuarComo('regente_farmacia');
        $this->getJson("/api/v1/dispensaciones/{$disp['id']}")->assertOk();

        rn10SinDatosPersonales(rn10LeerLog($this->log), $this->paciente, 'El log');
    });

    it('RN-10-AC8: errores de validación con datos del paciente en el cuerpo no los llevan al log', function (string $uri, Closure $cuerpo) {
        actuarComo('regente_farmacia');

        $r = $this->postJson($uri, $cuerpo($this->paciente), cabecerasDispensacion());

        esperarError($r, 422, 'VALIDACION');
        rn10SinDatosPersonales(rn10LeerLog($this->log), $this->paciente, 'El log');
    })->with([
        'búsqueda con tipo inválido y datos personales' => ['/api/v1/pacientes/buscar', fn (Paciente $p) => [
            'tipo_documento' => 'ZZ',
            'numero_documento' => RN10_DOC,
            'nombres' => RN10_NOMBRES,
            'apellidos' => RN10_APELLIDOS,
            'telefono' => RN10_TELEFONO,
            'correo' => RN10_CORREO,
            'direccion' => RN10_DIRECCION,
        ]],
        'búsqueda con documento demasiado largo que contiene el real' => ['/api/v1/pacientes/buscar', fn (Paciente $p) => [
            'numero_documento' => str_repeat(RN10_DOC, 30),
            'correo' => RN10_CORREO,
        ]],
        'dispensación con claves desconocidas de paciente' => ['/api/v1/dispensaciones', fn (Paciente $p) => [
            'paciente_id' => $p->id,
            'numero_documento' => RN10_DOC,
            'nombres' => RN10_NOMBRES,
            'apellidos' => RN10_APELLIDOS,
            'telefono' => RN10_TELEFONO,
            'correo' => RN10_CORREO,
            'direccion' => RN10_DIRECCION,
            'items' => [],
        ]],
    ]);

    it('RN-10-AC8: excepción forzada en una ruta con paciente y datos personales en el cuerpo → el log registra el fallo sin datos personales', function () {
        Route::middleware(['api', 'auth:sanctum'])
            ->post('api/v1/prueba-rn10-excepcion/{paciente}', function (Paciente $paciente): never {
                throw new RuntimeException('Fallo forzado RN-10 sobre el paciente '.$paciente->id);
            });
        actuarComo('regente_farmacia');

        $r = $this->postJson("/api/v1/prueba-rn10-excepcion/{$this->paciente->id}", [
            'numero_documento' => RN10_DOC,
            'nombres' => RN10_NOMBRES,
            'apellidos' => RN10_APELLIDOS,
            'telefono' => RN10_TELEFONO,
            'correo' => RN10_CORREO,
            'direccion' => RN10_DIRECCION,
        ]);

        esperarError($r, 500, 'ERROR_INTERNO');
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'La respuesta 500');
        $log = rn10LeerLog($this->log);
        expect($log)->toContain('Fallo forzado RN-10 sobre el paciente '.$this->paciente->id);
        rn10SinDatosPersonales($log, $this->paciente, 'El log');
    });

    it('RN-10-AC8: fallo de BD durante la búsqueda (documento en los bindings) → 500 y el log no contiene el documento ni otros datos', function () {
        actuarComo('regente_farmacia');

        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE pacientes RENAME TO pacientes_rn10_oculta');
            $r = $this->postJson('/api/v1/pacientes/buscar', ['tipo_documento' => 'CC', 'numero_documento' => RN10_DOC]);
        } finally {
            DB::rollBack();
        }

        esperarError($r, 500, 'ERROR_INTERNO');
        $log = rn10LeerLog($this->log);
        expect($log)->not->toBe('', 'El fallo de BD no se registró en el log');
        rn10SinDatosPersonales($log, $this->paciente, 'El log');
        expect(Paciente::query()->count())->toBe(1);
    });

    it('RN-10-AC8: los registros de log de una petición llevan su correlation_id', function () {
        Route::middleware(['api', 'auth:sanctum'])
            ->post('api/v1/prueba-rn10-correlacion/{paciente}', function (Paciente $paciente): never {
                throw new RuntimeException('Fallo forzado RN-10 correlación');
            });
        actuarComo('regente_farmacia');

        $r = $this->postJson("/api/v1/prueba-rn10-correlacion/{$this->paciente->id}");

        esperarError($r, 500, 'ERROR_INTERNO');
        $correlacion = (string) $r->headers->get('X-Correlation-Id');
        expect($correlacion)->not->toBe('')
            ->and(rn10LeerLog($this->log))->toContain($correlacion);
    });

    it('RN-10-AC8: un log explícito con datos del paciente en el contexto (y en marcadores del mensaje) sale redactado, conservando los ids', function () {
        Log::info('Consulta del paciente {numero_documento} {nombres} {correo}', [
            'paciente_id' => $this->paciente->id,
            'numero_documento' => RN10_DOC,
            'nombres' => RN10_NOMBRES,
            'apellidos' => RN10_APELLIDOS,
            'telefono' => RN10_TELEFONO,
            'correo' => RN10_CORREO,
            'direccion' => RN10_DIRECCION,
            'paciente' => [
                'id' => $this->paciente->id,
                'numero_documento' => RN10_DOC,
                'nombres' => RN10_NOMBRES,
                'apellidos' => RN10_APELLIDOS,
                'telefono' => RN10_TELEFONO,
                'correo' => RN10_CORREO,
                'direccion' => RN10_DIRECCION,
            ],
        ]);

        $log = rn10LeerLog($this->log);
        expect($log)->toContain('Consulta del paciente')
            ->and($log)->toContain('"paciente_id":'.$this->paciente->id);
        rn10SinDatosPersonales($log, $this->paciente, 'El log');
    });

    it('RN-10-AC8: el procesador de Monolog del canal por defecto redacta las claves sensibles a cualquier profundidad y conserva los ids', function () {
        $monolog = Log::channel()->getLogger();
        expect($monolog)->toBeInstanceOf(Logger::class);

        $procesadores = $monolog->getProcessors();
        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $propiedad = new ReflectionProperty($handler, 'processors');
                $procesadores = [...$procesadores, ...(array) $propiedad->getValue($handler)];
            }
        }
        expect($procesadores)->not->toBe([], 'El canal de log por defecto no tiene ningún procesador');

        $sensibles = [
            'numero_documento' => RN10_DOC,
            'nombres' => RN10_NOMBRES,
            'apellidos' => RN10_APELLIDOS,
            'telefono' => RN10_TELEFONO,
            'correo' => RN10_CORREO,
            'direccion' => RN10_DIRECCION,
        ];
        $registro = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Error,
            message: 'Registro de prueba RN-10',
            context: [
                'paciente_id' => $this->paciente->id,
                ...$sensibles,
                'paciente' => ['id' => $this->paciente->id, ...$sensibles],
                'peticion' => ['entrada' => ['pacientes' => [['id' => $this->paciente->id, ...$sensibles]]]],
            ],
            extra: ['entrada' => $sensibles],
        );

        foreach ($procesadores as $procesador) {
            $registro = $procesador($registro);
        }

        $serializado = (string) json_encode($registro->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rn10SinDatosPersonales($serializado, $this->paciente, 'El registro procesado');
        expect($registro->context['paciente_id'])->toBe($this->paciente->id)
            ->and($registro->context['paciente']['id'] ?? null)->toBe($this->paciente->id)
            ->and($registro->message)->toBe('Registro de prueba RN-10');
    });

    it('RN-10-AC8: un modelo, una colección u otro objeto serializable con datos del paciente en el contexto del log no deja datos personales', function (Closure $contexto) {
        $prescripcion = Prescripcion::factory()->create([
            'paciente_id' => $this->paciente->id,
            'observaciones' => 'Observación clínica sintética RN10 confidencial',
        ]);
        PrescripcionItem::factory()->create(['prescripcion_id' => $prescripcion->id, 'indicaciones' => 'Indicación sintética RN10 confidencial']);
        $paciente = $this->paciente->fresh(['prescripciones.items']);
        expect($paciente)->not->toBeNull();

        Log::warning('Prueba RN-10 objetos en el contexto', ['paciente_id' => $this->paciente->id, ...$contexto($paciente)]);

        $log = rn10LeerLog($this->log);
        expect($log)->toContain('Prueba RN-10 objetos en el contexto')
            ->and($log)->toContain('"paciente_id":'.$this->paciente->id)
            ->and($log)->not->toContain('RN10 confidencial');
        rn10SinDatosPersonales($log, $this->paciente, 'El log');
    })->with([
        'modelo' => [fn (Paciente $p) => ['paciente' => $p]],
        'modelo anidado en un array' => [fn (Paciente $p) => ['datos' => ['pacientes' => [$p]]]],
        'colección Eloquent' => [fn (Paciente $p) => ['pacientes' => Paciente::query()->whereKey($p->id)->get()]],
        'colección base de modelos' => [fn (Paciente $p) => ['pacientes' => collect([$p, $p->prescripciones->first()])]],
        'colección de arrays' => [fn (Paciente $p) => ['filas' => collect([$p->toArray()])]],
        'relación cargada (prescripciones con ítems)' => [fn (Paciente $p) => ['prescripciones' => $p->prescripciones]],
        'Arrayable y JsonSerializable (Fluent)' => [fn (Paciente $p) => ['datos' => new Fluent($p->toArray())]],
        'JsonSerializable' => [fn (Paciente $p) => ['datos' => new class($p->toArray()) implements JsonSerializable
        {
            /** @param  array<string, mixed>  $datos */
            public function __construct(private readonly array $datos) {}

            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return ['anidado' => $this->datos];
            }
        }]],
        'API Resource' => [fn (Paciente $p) => ['recurso' => new PacienteResource($p)]],
    ]);
});

/*
|--------------------------------------------------------------------------
| AC9: bitácora de operaciones sensibles
|--------------------------------------------------------------------------
*/

describe('RN-10-AC9 bitácora de operaciones sensibles', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC9: autorizar una dispensación de control especial registra usuario, acción, entidad e id', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: true);
        $antes = count(rn10Filas());
        $regente = actuarComo('regente_farmacia');

        $this->postJson("/api/v1/dispensaciones/{$disp['id']}/autorizar")->assertOk()->assertJsonPath('data.estado', 'CONFIRMADA');

        $fila = rn10AfirmarOperacion($antes, $regente, 'dispensacion', $disp['id']);
        rn10FilaSinDatosPersonales($fila, $this->paciente);
    });

    it('RN-10-AC9: rechazar una dispensación de control especial registra usuario, acción, entidad e id', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: true);
        $antes = count(rn10Filas());
        $regente = actuarComo('regente_farmacia');

        $this->postJson("/api/v1/dispensaciones/{$disp['id']}/rechazar", ['motivo' => 'Soporte de control especial incompleto'])
            ->assertOk()->assertJsonPath('data.estado', 'RECHAZADA');

        rn10AfirmarOperacion($antes, $regente, 'dispensacion', $disp['id']);
    });

    it('RN-10-AC9: un ajuste de inventario registra usuario, acción, entidad (movimiento de kardex) e id', function () {
        $existencia = rn10ExistenciaConStock(30);
        $antes = count(rn10Filas());
        $regente = actuarComo('regente_farmacia');

        $r = $this->postJson('/api/v1/inventario/ajustes', [
            'existencia_id' => $existencia->id,
            'cantidad' => -4,
            'motivo' => 'Conteo físico con faltante de prueba',
        ]);

        $r->assertCreated();
        rn10AfirmarOperacion($antes, $regente, 'movimiento_kardex', (int) cuerpoDatos($r)['id']);
    });

    it('RN-10-AC9: aprobar un traslado registra usuario, acción, entidad e id', function () {
        $traslado = rn10Traslado(TrasladoEstado::Solicitado, usuarioConRol('auxiliar_farmacia'));
        $antes = count(rn10Filas());
        $regente = actuarComo('regente_farmacia');

        $this->postJson("/api/v1/traslados/{$traslado->id}/aprobar")->assertOk()->assertJsonPath('data.estado', 'APROBADO');

        rn10AfirmarOperacion($antes, $regente, 'traslado', $traslado->id);
    });

    it('RN-10-AC9: anular un traslado registra usuario, acción, entidad e id', function (string $rol) {
        $creador = usuarioConRol('auxiliar_farmacia');
        $traslado = rn10Traslado(TrasladoEstado::Solicitado, $creador);
        $antes = count(rn10Filas());
        if ($rol === 'creador') {
            Sanctum::actingAs($creador);
            $actor = $creador;
        } else {
            $actor = actuarComo('regente_farmacia');
        }

        $this->postJson("/api/v1/traslados/{$traslado->id}/anular", ['motivo' => 'Pedido duplicado de prueba'])
            ->assertOk()->assertJsonPath('data.estado', 'ANULADO');

        rn10AfirmarOperacion($antes, $actor, 'traslado', $traslado->id);
    })->with(['creador', 'regente']);

    it('RN-10-AC9: resolver una discrepancia registra usuario, acción, entidad e id', function () {
        $traslado = rn10Traslado(TrasladoEstado::RecibidoParcial, usuarioConRol('auxiliar_farmacia'));
        $discrepancia = Discrepancia::query()->whereRelation('item', 'traslado_id', $traslado->id)->sole();
        $antes = count(rn10Filas());
        $regente = actuarComo('regente_farmacia');

        $this->postJson("/api/v1/discrepancias/{$discrepancia->id}/resolver", [
            'tipo' => 'REINTEGRO_ORIGEN',
            'motivo' => 'Unidades devueltas al origen en prueba',
        ])->assertOk()->assertJsonPath('data.estado', 'RESUELTA');

        rn10AfirmarOperacion($antes, $regente, 'discrepancia', $discrepancia->id);
    });

    it('RN-10-AC9: cada tipo de operación sensible registra una acción distinta', function () {
        $acciones = [];

        $disp = rn10Dispensacion($this->paciente, controlEspecial: true);
        $antes = count(rn10Filas());
        actuarComo('regente_farmacia');
        $this->postJson("/api/v1/dispensaciones/{$disp['id']}/autorizar")->assertOk();
        $acciones['autorizar'] = rn10FilasOperacion($antes)[0]->accion;

        $disp2 = rn10Dispensacion(rn10Paciente(['numero_documento' => '6600112233']), controlEspecial: true);
        $antes = count(rn10Filas());
        actuarComo('regente_farmacia');
        $this->postJson("/api/v1/dispensaciones/{$disp2['id']}/rechazar", ['motivo' => 'Soporte de control especial incompleto'])->assertOk();
        $acciones['rechazar'] = rn10FilasOperacion($antes)[0]->accion;

        $existencia = rn10ExistenciaConStock(10);
        $antes = count(rn10Filas());
        $this->postJson('/api/v1/inventario/ajustes', ['existencia_id' => $existencia->id, 'cantidad' => 2, 'motivo' => 'Sobrante en conteo de prueba'])->assertCreated();
        $acciones['ajuste'] = rn10FilasOperacion($antes)[0]->accion;

        $t1 = rn10Traslado(TrasladoEstado::Solicitado, usuarioConRol('auxiliar_farmacia'));
        $antes = count(rn10Filas());
        $this->postJson("/api/v1/traslados/{$t1->id}/aprobar")->assertOk();
        $acciones['aprobar'] = rn10FilasOperacion($antes)[0]->accion;

        $t2 = rn10Traslado(TrasladoEstado::Solicitado, usuarioConRol('auxiliar_farmacia'));
        $antes = count(rn10Filas());
        $this->postJson("/api/v1/traslados/{$t2->id}/anular", ['motivo' => 'Pedido duplicado de prueba'])->assertOk();
        $acciones['anular'] = rn10FilasOperacion($antes)[0]->accion;

        $t3 = rn10Traslado(TrasladoEstado::RecibidoParcial, usuarioConRol('auxiliar_farmacia'));
        $disc = Discrepancia::query()->whereRelation('item', 'traslado_id', $t3->id)->sole();
        $antes = count(rn10Filas());
        $this->postJson("/api/v1/discrepancias/{$disc->id}/resolver", ['tipo' => 'PERDIDA', 'motivo' => 'Unidades perdidas en tránsito de prueba'])->assertOk();
        $acciones['resolver'] = rn10FilasOperacion($antes)[0]->accion;

        expect(array_unique(array_values($acciones)))->toHaveCount(6);
    });

    /*
     * S-81: la fila de la bitácora va en la MISMA transacción que la operación. Si escribirla falla
     * (trigger temporal que rechaza todo INSERT en auditoria_accesos), la operación entera se
     * revierte: 500 ERROR_INTERNO sin SQL ni valores, ninguna fila nueva y el negocio intacto
     * (estado de la entidad, existencias, kardex). Si la fila se escribiera después de confirmar la
     * operación, el cambio de negocio quedaría hecho (y, en el test, la transacción exterior abortada).
     */
    it('RN-10-AC9 S-81: si la escritura en la bitácora falla, la operación sensible se revierte entera (500 sin SQL, sin fila y sin cambios de negocio)', function (Closure $preparar) {
        $log = rn10CapturarLog();
        /** @var array{metodo: string, uri: string, cuerpo: array<string, mixed>, tabla: string, id: int, estado: string|null} $op */
        $op = $preparar($this->paciente);
        olvidarAutenticacion();
        actuarComo('regente_farmacia');
        $antes = rn10Instantanea();
        $kardexAntes = MovimientoKardex::query()->count();
        $stockAntes = DB::table('existencias')->orderBy('id')->pluck('cantidad', 'id')->all();
        rn10HacerFallarLaBitacora();

        $r = $this->json($op['metodo'], $op['uri'], $op['cuerpo']);

        expect(rn10TransaccionSana())->toBeTrue(
            'La transacción del test quedó abortada: la fila de la bitácora se escribió fuera de la transacción de la operación (S-81)',
        );
        esperarError($r, 500, 'ERROR_INTERNO');
        $cuerpo = (string) $r->getContent();
        foreach (['rn10', 'SQLSTATE', 'P0001', 'auditoria_accesos', 'INSERT', 'insert into', ...array_filter(array_values($op['cuerpo']), 'is_string')] as $prohibido) {
            expect(str_contains($cuerpo, (string) $prohibido))->toBeFalse("La respuesta 500 contiene «{$prohibido}»");
        }
        expect(DB::table($op['tabla'])->where('id', $op['id'])->exists())->toBeTrue();
        if ($op['estado'] !== null) {
            expect(DB::table($op['tabla'])->where('id', $op['id'])->value('estado'))->toBe($op['estado']);
        }
        expect(MovimientoKardex::query()->count())->toBe($kardexAntes)
            ->and(DB::table('existencias')->orderBy('id')->pluck('cantidad', 'id')->all())->toBe($stockAntes)
            ->and(rn10Instantanea())->toEqual($antes);
        // El 500 lo causó el trigger (error de BD registrado con su SQLSTATE) y el log no lleva el SQL.
        $contenido = rn10LeerLog($log);
        expect($contenido)->toContain('"sqlstate":"P0001"')
            ->and($contenido)->not->toContain('insert into');
    })->with([
        'autorizar dispensación' => [function (Paciente $paciente): array {
            $disp = rn10Dispensacion($paciente, controlEspecial: true);

            return ['metodo' => 'POST', 'uri' => "/api/v1/dispensaciones/{$disp['id']}/autorizar", 'cuerpo' => [],
                'tabla' => 'dispensaciones', 'id' => $disp['id'], 'estado' => 'PENDIENTE_AUTORIZACION'];
        }],
        'rechazar dispensación' => [function (Paciente $paciente): array {
            $disp = rn10Dispensacion($paciente, controlEspecial: true);

            return ['metodo' => 'POST', 'uri' => "/api/v1/dispensaciones/{$disp['id']}/rechazar", 'cuerpo' => ['motivo' => 'Soporte de control especial incompleto'],
                'tabla' => 'dispensaciones', 'id' => $disp['id'], 'estado' => 'PENDIENTE_AUTORIZACION'];
        }],
        'ajuste de inventario' => [function (): array {
            $existencia = rn10ExistenciaConStock(30);

            return ['metodo' => 'POST', 'uri' => '/api/v1/inventario/ajustes', 'cuerpo' => ['existencia_id' => $existencia->id, 'cantidad' => -4, 'motivo' => 'Conteo físico con faltante de prueba'],
                // La existencia no tiene estado: su cantidad (30) la comprueban el stock y la instantánea.
                'tabla' => 'existencias', 'id' => $existencia->id, 'estado' => null];
        }],
        'aprobar traslado' => [function (): array {
            $traslado = rn10Traslado(TrasladoEstado::Solicitado, usuarioConRol('auxiliar_farmacia'));

            return ['metodo' => 'POST', 'uri' => "/api/v1/traslados/{$traslado->id}/aprobar", 'cuerpo' => [],
                'tabla' => 'traslados', 'id' => $traslado->id, 'estado' => 'SOLICITADO'];
        }],
        'anular traslado' => [function (): array {
            $traslado = rn10Traslado(TrasladoEstado::Solicitado, usuarioConRol('auxiliar_farmacia'));

            return ['metodo' => 'POST', 'uri' => "/api/v1/traslados/{$traslado->id}/anular", 'cuerpo' => ['motivo' => 'Pedido duplicado de prueba'],
                'tabla' => 'traslados', 'id' => $traslado->id, 'estado' => 'SOLICITADO'];
        }],
        'resolver discrepancia' => [function (): array {
            $traslado = rn10Traslado(TrasladoEstado::RecibidoParcial, usuarioConRol('auxiliar_farmacia'));
            $discrepancia = Discrepancia::query()->whereRelation('item', 'traslado_id', $traslado->id)->sole();

            return ['metodo' => 'POST', 'uri' => "/api/v1/discrepancias/{$discrepancia->id}/resolver", 'cuerpo' => ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => 'Unidades devueltas al origen en prueba'],
                'tabla' => 'discrepancias', 'id' => $discrepancia->id, 'estado' => 'PENDIENTE'];
        }],
    ]);

    /*
     * Operación fallida (409/422) → ninguna fila nueva (la bitácora va en la misma transacción) y
     * el estado de negocio no cambia.
     */

    it('RN-10-AC9: autorizar que falla por stock insuficiente (409) no deja fila en la bitácora', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: true, stock: 5, cantidad: 3);
        Existencia::query()->whereKey($disp['existencia']->id)->update(['cantidad' => 1]);
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        actuarComo('regente_farmacia');

        esperarError($this->postJson("/api/v1/dispensaciones/{$disp['id']}/autorizar"), 409, 'STOCK_INSUFICIENTE');

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    });

    it('RN-10-AC9: autorizar que falla por prescripción no vigente (422) no deja fila en la bitácora', function () {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: true);
        Prescripcion::query()->whereKey($disp['prescripcion']->id)->update(['estado' => 'ANULADA']);
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        actuarComo('regente_farmacia');

        esperarError($this->postJson("/api/v1/dispensaciones/{$disp['id']}/autorizar"), 422, 'PRESCRIPCION_NO_VIGENTE');

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    });

    it('RN-10-AC9: autorizar o rechazar una dispensación ya resuelta (409 TRANSICION_INVALIDA) no deja fila', function (string $primera, string $segunda) {
        $disp = rn10Dispensacion($this->paciente, controlEspecial: true);
        actuarComo('regente_farmacia');
        $this->postJson("/api/v1/dispensaciones/{$disp['id']}/{$primera}", $primera === 'rechazar' ? ['motivo' => 'Soporte de control especial incompleto'] : [])->assertOk();
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        olvidarAutenticacion();
        actuarComo('regente_farmacia');

        esperarError(
            $this->postJson("/api/v1/dispensaciones/{$disp['id']}/{$segunda}", $segunda === 'rechazar' ? ['motivo' => 'Soporte de control especial incompleto'] : []),
            409,
            'TRANSICION_INVALIDA',
        );

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    })->with([
        'autorizar dos veces' => ['autorizar', 'autorizar'],
        'rechazar dos veces' => ['rechazar', 'rechazar'],
        'autorizar una rechazada' => ['rechazar', 'autorizar'],
        'rechazar una autorizada' => ['autorizar', 'rechazar'],
    ]);

    it('RN-10-AC9: ajuste que falla (409 saldo negativo, 422 validación) no deja fila en la bitácora', function (int $cantidad, string $motivo, int $status, string $codigo) {
        $existencia = rn10ExistenciaConStock(3);
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        actuarComo('regente_farmacia');

        esperarError($this->postJson('/api/v1/inventario/ajustes', [
            'existencia_id' => $existencia->id,
            'cantidad' => $cantidad,
            'motivo' => $motivo,
        ]), $status, $codigo);

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    })->with([
        'saldo negativo' => [-4, 'Conteo físico con faltante de prueba', 409, 'STOCK_INSUFICIENTE'],
        'cantidad 0' => [0, 'Conteo físico con faltante de prueba', 422, 'VALIDACION'],
        'motivo corto' => [-1, 'corto', 422, 'VALIDACION'],
    ]);

    it('RN-10-AC9: aprobar un traslado en estado inválido (409) no deja fila en la bitácora', function (TrasladoEstado $estado) {
        $traslado = rn10Traslado($estado, usuarioConRol('auxiliar_farmacia'));
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        actuarComo('regente_farmacia');

        esperarError($this->postJson("/api/v1/traslados/{$traslado->id}/aprobar"), 409, 'TRANSICION_INVALIDA');

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    })->with([
        'BORRADOR' => [TrasladoEstado::Borrador],
        'APROBADO' => [TrasladoEstado::Aprobado],
        'EN_TRANSITO' => [TrasladoEstado::EnTransito],
    ]);

    it('RN-10-AC9: anular un traslado EN_TRANSITO (409, S-09) no deja fila en la bitácora', function () {
        $traslado = rn10Traslado(TrasladoEstado::EnTransito, usuarioConRol('auxiliar_farmacia'));
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();
        actuarComo('regente_farmacia');

        esperarError($this->postJson("/api/v1/traslados/{$traslado->id}/anular", ['motivo' => 'Pedido duplicado de prueba']), 409, 'TRANSICION_INVALIDA');

        expect(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    });

    it('RN-10-AC9: resolver una discrepancia ya resuelta (409) no deja una segunda fila', function () {
        $traslado = rn10Traslado(TrasladoEstado::RecibidoParcial, usuarioConRol('auxiliar_farmacia'));
        $discrepancia = Discrepancia::query()->whereRelation('item', 'traslado_id', $traslado->id)->sole();
        actuarComo('regente_farmacia');
        $cuerpo = ['tipo' => 'REINTEGRO_ORIGEN', 'motivo' => 'Unidades devueltas al origen en prueba'];
        $this->postJson("/api/v1/discrepancias/{$discrepancia->id}/resolver", $cuerpo)->assertOk();
        $antesFilas = rn10Filas();
        $antes = rn10EstadoNegocio();

        $r = $this->postJson("/api/v1/discrepancias/{$discrepancia->id}/resolver", $cuerpo);

        expect($r->status())->toBe(409)
            ->and($r->json('error.codigo'))->toBeString()
            ->and(rn10Filas())->toEqual($antesFilas)
            ->and(rn10EstadoNegocio())->toBe($antes);
    });
});

/*
|--------------------------------------------------------------------------
| AC10: consulta e inmutabilidad de la bitácora
|--------------------------------------------------------------------------
*/

describe('RN-10-AC10 consulta e inmutabilidad de la bitácora', function () {
    beforeEach(function () {
        rn10Congelar();
        $this->paciente = rn10Paciente();
    });

    it('RN-10-AC10: auditor y admin reciben 200 paginado con las filas de la bitácora y sin datos personales', function (string $rol) {
        actuarComo('regente_farmacia');
        for ($i = 0; $i < 30; $i++) {
            $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();
        }
        olvidarAutenticacion();
        $total = count(rn10Filas());
        expect($total)->toBe(30);
        actuarComo($rol);

        $r = $this->getJson('/api/v1/auditoria');

        $r->assertOk();
        $porPagina = $r->json('meta.per_page');
        expect($r->json('data'))->toBeArray()
            ->and($r->json('meta.total'))->toBe(30)
            ->and($porPagina)->toBeInt()->toBeGreaterThan(0)
            ->and($r->json('data'))->toHaveCount(min(30, (int) $porPagina))
            ->and($r->json('data.0'))->toHaveKeys(['id', 'usuario_id', 'paciente_id', 'accion', 'resultado', 'correlation_id']);
        $idsBd = DB::table('auditoria_accesos')->pluck('id')->all();
        foreach ($r->json('data') as $fila) {
            expect($idsBd)->toContain($fila['id']);
        }
        rn10SinDatosPersonales(rn10CuerpoCrudo($r), $this->paciente, 'GET /auditoria');
        // Consultar la bitácora no es una consulta de paciente.
        expect(count(rn10Filas()))->toBe($total);
    })->with(['auditor', 'admin']);

    it('RN-10-AC10: auxiliar, regente y médico → 403 NO_AUTORIZADO en GET /auditoria', function (string $rol) {
        actuarComo($rol);

        esperarError($this->getJson('/api/v1/auditoria'), 403, 'NO_AUTORIZADO');
    })->with(['auxiliar_farmacia', 'regente_farmacia', 'medico']);

    it('RN-10-AC10: sin token → 401 NO_AUTENTICADO en GET /auditoria', function () {
        esperarError($this->getJson('/api/v1/auditoria'), 401, 'NO_AUTENTICADO');
    });

    it('RN-10-AC10: no hay escritura por HTTP sobre la bitácora (405 y nada cambia)', function (string $metodo, string $uri) {
        actuarComo('regente_farmacia');
        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();
        $antes = rn10Filas();
        olvidarAutenticacion();
        actuarComo('admin');
        $id = $antes[0]->id;

        $r = $this->json($metodo, str_replace('{id}', (string) $id, $uri), ['accion' => 'PACIENTE_CONSULTAR']);

        expect($r->status())->toBeIn([404, 405])
            ->and(rn10Filas())->toEqual($antes);
    })->with([
        'POST /auditoria' => ['POST', '/api/v1/auditoria'],
        'PUT /auditoria/{id}' => ['PUT', '/api/v1/auditoria/{id}'],
        'PATCH /auditoria/{id}' => ['PATCH', '/api/v1/auditoria/{id}'],
        'DELETE /auditoria/{id}' => ['DELETE', '/api/v1/auditoria/{id}'],
    ]);

    it('RN-10-AC10: UPDATE, DELETE y TRUNCATE directos sobre auditoria_accesos los rechaza el trigger y la fila queda intacta', function (Closure $sql) {
        actuarComo('regente_farmacia');
        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();
        $antes = rn10Filas();
        expect($antes)->toHaveCount(1);
        $otro = usuarioConRol('auditor');

        $sqlstate = rn10Sqlstate(fn () => $sql($antes[0], $otro));

        expect($sqlstate)->not->toBeNull('La BD permitió modificar auditoria_accesos')
            ->and(rn10Filas())->toEqual($antes);
    })->with([
        'UPDATE resultado' => [fn (object $f) => DB::update("UPDATE auditoria_accesos SET resultado = 'DENEGADO' WHERE id = ?", [$f->id])],
        'UPDATE usuario_id' => [fn (object $f, User $otro) => DB::update('UPDATE auditoria_accesos SET usuario_id = ? WHERE id = ?', [$otro->id, $f->id])],
        'UPDATE ruta' => [fn (object $f) => DB::update("UPDATE auditoria_accesos SET ruta = 'api/v1/otra' WHERE id = ?", [$f->id])],
        'UPDATE created_at' => [fn (object $f) => DB::update("UPDATE auditoria_accesos SET created_at = created_at - interval '1 day' WHERE id = ?", [$f->id])],
        'UPDATE sin cambios (SET col = col)' => [fn (object $f) => DB::update('UPDATE auditoria_accesos SET accion = accion WHERE id = ?', [$f->id])],
        'UPDATE de toda la tabla' => [fn () => DB::statement('UPDATE auditoria_accesos SET ip = ip')],
        'DELETE por id' => [fn (object $f) => DB::delete('DELETE FROM auditoria_accesos WHERE id = ?', [$f->id])],
        'DELETE de toda la tabla' => [fn () => DB::delete('DELETE FROM auditoria_accesos')],
        'DELETE … RETURNING' => [fn (object $f) => DB::select('DELETE FROM auditoria_accesos WHERE id = ? RETURNING id', [$f->id])],
        'TRUNCATE' => [fn () => DB::statement('TRUNCATE auditoria_accesos')],
        'TRUNCATE pacientes CASCADE' => [fn () => DB::statement('TRUNCATE pacientes CASCADE')],
        'TRUNCATE users CASCADE' => [fn () => DB::statement('TRUNCATE users CASCADE')],
    ]);

    it('RN-10-AC10: desde Eloquent, modificar o borrar una fila de la bitácora lanza excepción y la fila queda intacta', function (Closure $operacion) {
        actuarComo('regente_farmacia');
        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();
        $antes = rn10Filas();
        $modelo = AuditoriaAcceso::query()->findOrFail($antes[0]->id);

        $lanzo = false;
        try {
            DB::transaction(fn () => $operacion($modelo));
        } catch (Throwable) {
            $lanzo = true;
        }

        expect($lanzo)->toBeTrue('Eloquent permitió modificar la bitácora sin excepción')
            ->and(rn10Filas())->toEqual($antes);
    })->with([
        'update()' => [fn (AuditoriaAcceso $m) => $m->update(['ruta' => 'api/v1/otra'])],
        'save() tras cambiar un atributo' => [function (AuditoriaAcceso $m) {
            $m->setAttribute('ip', '1.2.3.4');
            $m->save();
        }],
        'delete()' => [fn (AuditoriaAcceso $m) => $m->delete()],
        'forceDelete()' => [fn (AuditoriaAcceso $m) => $m->forceDelete()],
        'builder update()' => [fn (AuditoriaAcceso $m) => AuditoriaAcceso::query()->whereKey($m->id)->update(['ip' => '1.2.3.4'])],
        'builder delete()' => [fn (AuditoriaAcceso $m) => AuditoriaAcceso::query()->whereKey($m->id)->delete()],
        'destroy()' => [fn (AuditoriaAcceso $m) => AuditoriaAcceso::destroy($m->id)],
    ]);

    it('RN-10-AC10 S-82: si el trigger de la bitácora rechaza un UPDATE en una petición → 409 BITACORA_INMUTABLE sin SQL ni valores, registrado en el log, y la fila intacta', function () {
        $log = rn10CapturarLog();
        actuarComo('regente_farmacia');
        $this->getJson("/api/v1/pacientes/{$this->paciente->id}")->assertOk();
        $antes = rn10Filas();
        expect($antes)->toHaveCount(1);
        $ipFalsa = '203.0.113.77';
        Route::middleware(['api', 'auth:sanctum'])
            ->post('api/v1/prueba-rn10-bitacora/{id}', function (int $id) use ($ipFalsa): array {
                // Escritura directa por el query builder: no pasa por los eventos del modelo, así que
                // la frena el trigger de la BD (23001). En su propia transacción, como cualquier escritura.
                DB::transaction(fn () => AuditoriaAcceso::query()->whereKey($id)->update(['ip' => $ipFalsa]));

                return ['ok' => true];
            })->whereNumber('id');

        $r = $this->postJson("/api/v1/prueba-rn10-bitacora/{$antes[0]->id}");

        esperarError($r, 409, 'BITACORA_INMUTABLE');
        $cuerpo = (string) $r->getContent();
        foreach ([$ipFalsa, 'SQLSTATE', 'UPDATE', 'auditoria_accesos', 'update "', 'where'] as $prohibido) {
            expect(str_contains($cuerpo, $prohibido))->toBeFalse("La respuesta 409 contiene «{$prohibido}»");
        }
        expect(rn10Filas())->toEqual($antes);
        $contenido = rn10LeerLog($log);
        expect($contenido)->toContain('ERROR')
            ->and($contenido)->toContain(ErroresBaseDatos::RESTRICCION_VIOLADA)
            ->and($contenido)->not->toContain($ipFalsa);
    });

    it('RN-10-AC7: la búsqueda solo por numero_documento tiene un índice que empieza por esa columna (el único compuesto empieza por tipo_documento)', function () {
        $indices = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'pacientes'"))
            ->pluck('indexdef')
            ->filter(fn (string $def): bool => preg_match('/\(\s*numero_documento\s*[,)]/', $def) === 1);

        expect($indices)->not->toBeEmpty('No hay un índice de pacientes que empiece por numero_documento');
    });
});
