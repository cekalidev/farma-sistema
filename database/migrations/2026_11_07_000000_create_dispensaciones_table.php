<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-02: cabecera de la dispensación. Las columnas de RN-04 (FK compuesta paciente↔prescripción),
 * RN-05 (estado, autorización/rechazo) y RN-09 (idempotencia) se crean ya con sus restricciones
 * para no reescribir la tabla en esas RN; su lógica se implementa en ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->unsignedBigInteger('prescripcion_id');
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('dispensado_por_id')->constrained('users')->restrictOnDelete();
            // RN-05 / S-06: CONFIRMADA (stock descontado), PENDIENTE_AUTORIZACION (sin mover stock), RECHAZADA.
            $table->string('estado', 30);
            $table->foreignId('autorizado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('autorizado_en')->nullable();
            $table->foreignId('rechazado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rechazado_en')->nullable();
            $table->string('motivo_rechazo', 500)->nullable();
            // RN-09 / S-10 / S-11: clave del cliente y hash del cuerpo canónico; alcance por usuario.
            $table->string('idempotency_key', 255)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamps();

            // RN-04: la prescripción pertenece al paciente de la dispensación.
            $table->foreign(['prescripcion_id', 'paciente_id'], 'dispensaciones_prescripcion_paciente_foreign')
                ->references(['id', 'paciente_id'])->on('prescripciones')
                ->restrictOnDelete();
            // RN-09: un reintento con la misma clave choca aquí (NULL no colisiona: sin clave no hay unicidad).
            $table->unique(['dispensado_por_id', 'idempotency_key'], 'dispensaciones_usuario_idempotency_unique');
            // Destinos de las FK compuestas de dispensacion_items y dispensacion_detalles.
            $table->unique(['id', 'prescripcion_id'], 'dispensaciones_id_prescripcion_unique');
            $table->unique(['id', 'bodega_id'], 'dispensaciones_id_bodega_unique');

            $table->index('paciente_id');
            $table->index('prescripcion_id');
            $table->index(['bodega_id', 'created_at']);
            $table->index('estado');
        });

        $checks = [
            'estado_check' => "estado IN ('CONFIRMADA', 'PENDIENTE_AUTORIZACION', 'RECHAZADA')",
            // RN-05-AC9: un regente no autoriza ni rechaza lo que él mismo dispensó.
            'autorizador_distinto_check' => 'autorizado_por_id IS NULL OR autorizado_por_id <> dispensado_por_id',
            'rechazador_distinto_check' => 'rechazado_por_id IS NULL OR rechazado_por_id <> dispensado_por_id',
            'autorizacion_completa_check' => '(autorizado_por_id IS NULL) = (autorizado_en IS NULL)',
            'rechazo_completo_check' => '(rechazado_por_id IS NULL) = (rechazado_en IS NULL)'
                .' AND (rechazado_por_id IS NULL) = (motivo_rechazo IS NULL)',
            // Coherencia estado ↔ columnas de RN-05. CONFIRMADA sin autorización = sin control especial.
            'estado_coherente_check' => "(estado = 'CONFIRMADA' AND rechazado_por_id IS NULL)"
                ." OR (estado = 'PENDIENTE_AUTORIZACION' AND autorizado_por_id IS NULL AND rechazado_por_id IS NULL)"
                ." OR (estado = 'RECHAZADA' AND autorizado_por_id IS NULL AND rechazado_por_id IS NOT NULL)",
            'motivo_rechazo_check' => 'motivo_rechazo IS NULL OR char_length(btrim(motivo_rechazo)) > 0',
            'idempotencia_completa_check' => '(idempotency_key IS NULL) = (request_hash IS NULL)',
            'idempotency_key_check' => 'idempotency_key IS NULL OR char_length(idempotency_key) > 0',
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE dispensaciones ADD CONSTRAINT dispensaciones_{$nombre} CHECK ({$condicion})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispensaciones');
    }
};
