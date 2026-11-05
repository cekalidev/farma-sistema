<?php

declare(strict_types=1);

namespace App\Services\Dispensacion;

use App\Enums\AccionAuditoria;
use App\Enums\EntidadAuditada;
use App\Enums\EstadoDispensacion;
use App\Exceptions\Dominio\AutorizacionMismoUsuario;
use App\Exceptions\Dominio\PrescripcionNoVigente;
use App\Exceptions\Dominio\StockInsuficiente;
use App\Exceptions\Dominio\TransicionInvalida;
use App\Models\Dispensacion;
use App\Models\Prescripcion;
use App\Services\Auditoria\Bitacora;
use App\Support\Negocio;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RN-05: un segundo regente autoriza (salida FEFO + kardex) o rechaza (sin movimientos) una
 * dispensación PENDIENTE_AUTORIZACION de control especial (S-06).
 *
 * Concurrencia (RN-05-AC10): la dispensación se relee con FOR UPDATE dentro de la transacción y el
 * estado se comprueba sobre la fila bloqueada. Dos autorizaciones (o autorizar y rechazar) de la
 * misma pendiente se serializan en ese bloqueo; la segunda ve el estado final de la primera y
 * responde 409 TRANSICION_INVALIDA, así que solo hay una salida de stock. El guard
 * `detalles()->exists()` de SalidaStock queda como defensa adicional, no como protección.
 *
 * Orden de bloqueo (S-56): dispensación (FOR UPDATE) → prescripción (FOR SHARE) → existencias (id
 * asc, FOR UPDATE, dentro de SalidaStock). La creación nunca bloquea una dispensación existente ni
 * la fila de la prescripción (la lee sin bloqueo; el INSERT de su cabecera solo toma FOR KEY SHARE
 * por la FK, compatible con FOR SHARE), así que no hay ciclo con ella. Dos autorizaciones de la
 * misma prescripción comparten el FOR SHARE sin esperarse. No se bloquean los
 * prescripcion_items: autorizar pasa de PENDIENTE_AUTORIZACION a CONFIRMADA y ambos estados ya
 * cuentan para el tope (CalculadorPendiente), de modo que el pendiente no cambia; rechazar solo lo
 * AUMENTA, y una creación simultánea que leyera el valor anterior sería más restrictiva, nunca
 * permitiría superar lo prescrito (RN-04-AC8 sigue protegido por el bloqueo de la creación).
 */
final class AutorizacionDispensacion
{
    public const ACCION_AUTORIZAR = 'autorizar';

    public const ACCION_RECHAZAR = 'rechazar';

    public function __construct(
        private readonly SalidaStock $salida,
        private readonly Bitacora $bitacora,
    ) {}

    /**
     * RN-05-AC3: CONFIRMADA, autorizado_por_id/autorizado_en y salida FEFO con kardex (usuario del
     * movimiento = el regente que autoriza, porque su acción es la que mueve el stock). FEFO se
     * calcula con la fecha de hoy al autorizar (un lote que venció mientras esperaba no se usa).
     *
     * Al autorizar se revalida lo que la creación exigía y puede cambiar mientras la dispensación
     * espera, porque es ahora cuando sale el stock: la bodega sigue activa (S-46) y la prescripción
     * sigue vigente (RN-04-AC2/AC3, S-05). Ambas comprobaciones van tras bloquear la dispensación y
     * comprobar su estado (una CONFIRMADA o RECHAZADA responde 409 TRANSICION_INVALIDA, no 422) y en
     * el mismo orden que en la creación: bodega → prescripción → stock. Rechazar no revalida nada.
     *
     * @throws AutorizacionMismoUsuario (403, RN-05-AC4)
     * @throws TransicionInvalida (409, RN-05-AC7/AC10)
     * @throws ValidationException (422 VALIDACION bodega_id, bodega desactivada mientras esperaba, S-46)
     * @throws PrescripcionNoVigente (422, vencida o anulada mientras esperaba: rollback, sigue pendiente)
     * @throws StockInsuficiente (409, RN-05-AC6: rollback, sigue PENDIENTE_AUTORIZACION)
     */
    public function autorizar(Dispensacion $dispensacion, int $usuarioId): Dispensacion
    {
        $this->exigirOtroUsuario($dispensacion, $usuarioId);
        $hoy = Negocio::hoy();

        $autorizada = DB::transaction(function () use ($dispensacion, $usuarioId, $hoy): Dispensacion {
            $bloqueada = $this->bloquearPendiente($dispensacion, self::ACCION_AUTORIZAR);
            DispensacionService::exigirBodegaActiva($bloqueada->bodega_id);
            $this->exigirPrescripcionVigente($bloqueada, $hoy);

            $this->salida->descontar($bloqueada, $usuarioId, $hoy);

            $bloqueada->forceFill([
                'estado' => EstadoDispensacion::Confirmada,
                'autorizado_por_id' => $usuarioId,
                'autorizado_en' => now(),
            ])->save();

            // RN-10-AC9: operación sensible en la bitácora, en esta misma transacción.
            $this->bitacora->operacion($usuarioId, AccionAuditoria::DispensacionAutorizar, EntidadAuditada::Dispensacion, $bloqueada->id, $bloqueada->paciente_id);

            return $bloqueada;
        });

        return $autorizada->load(DispensacionService::relacionesRespuesta());
    }

    /**
     * RN-05-AC8: RECHAZADA con rechazado_por_id/rechazado_en/motivo_rechazo, sin stock ni kardex.
     * Sus ítems dejan de contar para el tope de la prescripción (RN-04-AC9).
     *
     * @throws AutorizacionMismoUsuario (403)
     * @throws TransicionInvalida (409, RN-05-AC7)
     */
    public function rechazar(Dispensacion $dispensacion, int $usuarioId, string $motivo): Dispensacion
    {
        $this->exigirOtroUsuario($dispensacion, $usuarioId);

        $rechazada = DB::transaction(function () use ($dispensacion, $usuarioId, $motivo): Dispensacion {
            $bloqueada = $this->bloquearPendiente($dispensacion, self::ACCION_RECHAZAR);

            $bloqueada->forceFill([
                'estado' => EstadoDispensacion::Rechazada,
                'rechazado_por_id' => $usuarioId,
                'rechazado_en' => now(),
                'motivo_rechazo' => $motivo,
            ])->save();

            // RN-10-AC9: operación sensible en la bitácora, en esta misma transacción.
            $this->bitacora->operacion($usuarioId, AccionAuditoria::DispensacionRechazar, EntidadAuditada::Dispensacion, $bloqueada->id, $bloqueada->paciente_id);

            return $bloqueada;
        });

        return $rechazada->load(DispensacionService::relacionesRespuesta());
    }

    /**
     * RN-05-AC4 / S-06: quien creó la dispensación no la autoriza ni la rechaza, sea cual sea su
     * rol. dispensado_por_id no cambia nunca, así que se puede comprobar fuera del bloqueo. Va antes
     * que el estado: un 403 de permiso precede a un 409 de conflicto (S-50).
     *
     * @throws AutorizacionMismoUsuario
     */
    public function exigirOtroUsuario(Dispensacion $dispensacion, int $usuarioId): void
    {
        if ($dispensacion->dispensado_por_id === $usuarioId) {
            throw AutorizacionMismoUsuario::paraDispensacion($dispensacion->id);
        }
    }

    /**
     * RN-04-AC2/AC3 al autorizar (S-05: vence hoy → vigente). Lee la prescripción con FOR SHARE
     * (segundo bloqueo, tras la dispensación y antes de las existencias): una anulación o un cambio
     * de vencimiento que llegara a la vez esperaría al commit de la autorización, así que la vigencia
     * comprobada sigue siendo cierta cuando sale el stock. FOR SHARE no choca con el FOR KEY SHARE
     * que toman las creaciones al insertar su cabecera ni con otras autorizaciones de la misma
     * prescripción.
     *
     * @throws PrescripcionNoVigente
     */
    private function exigirPrescripcionVigente(Dispensacion $dispensacion, CarbonInterface $hoy): void
    {
        $prescripcion = Prescripcion::query()->whereKey($dispensacion->prescripcion_id)->sharedLock()->firstOrFail();

        if (! $prescripcion->estaVigente($hoy)) {
            throw PrescripcionNoVigente::para($prescripcion);
        }
    }

    /**
     * Relee la dispensación con FOR UPDATE (primer bloqueo de la transacción, S-56) y exige que
     * siga PENDIENTE_AUTORIZACION.
     *
     * @throws TransicionInvalida
     */
    private function bloquearPendiente(Dispensacion $dispensacion, string $accion): Dispensacion
    {
        $bloqueada = Dispensacion::query()->whereKey($dispensacion->id)->lockForUpdate()->firstOrFail();

        if ($bloqueada->estado !== EstadoDispensacion::PendienteAutorizacion) {
            throw TransicionInvalida::paraDispensacion($bloqueada, $accion);
        }

        return $bloqueada;
    }
}
