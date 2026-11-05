<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-07: cabecera del traslado entre bodegas y su máquina de estados (App\Enums\TrasladoEstado).
 * Cada acción deja su par usuario + fecha; los CHECK exigen que las columnas casen con el estado,
 * así un UPDATE directo no puede dejar, p. ej., un EN_TRANSITO sin despachador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traslados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bodega_origen_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('bodega_destino_id')->constrained('bodegas')->restrictOnDelete();
            $table->string('estado', 20);
            // Texto libre del usuario (no se interpreta: se guarda y se devuelve tal cual).
            $table->text('observaciones')->nullable();
            $table->foreignId('creado_por_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('solicitado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('solicitado_en')->nullable();
            $table->foreignId('aprobado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('aprobado_en')->nullable();
            $table->foreignId('despachado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('despachado_en')->nullable();
            $table->foreignId('recibido_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('recibido_en')->nullable();
            $table->foreignId('anulado_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('anulado_en')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();

            $table->index('estado');
            $table->index('bodega_origen_id');
            $table->index('bodega_destino_id');
            $table->index('creado_por_id');
        });

        $checks = [
            'estado_check' => "estado IN ('BORRADOR', 'SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'RECIBIDO_PARCIAL', 'ANULADO')",
            'bodegas_distintas_check' => 'bodega_origen_id <> bodega_destino_id',
            // S-08 / RN-08: solo el creador solicita, así que solicitante = creador.
            'solicitante_creador_check' => 'solicitado_por_id IS NULL OR solicitado_por_id = creado_por_id',
            // RN-08: aquí irá CHECK (aprobado_por_id IS NULL OR aprobado_por_id <> solicitado_por_id).
            'solicitud_completa_check' => '(solicitado_por_id IS NULL) = (solicitado_en IS NULL)',
            'aprobacion_completa_check' => '(aprobado_por_id IS NULL) = (aprobado_en IS NULL)',
            'despacho_completo_check' => '(despachado_por_id IS NULL) = (despachado_en IS NULL)',
            'recepcion_completa_check' => '(recibido_por_id IS NULL) = (recibido_en IS NULL)',
            'anulacion_completa_check' => '(anulado_por_id IS NULL) = (anulado_en IS NULL)'
                .' AND (anulado_por_id IS NULL) = (motivo_anulacion IS NULL)',
            // Orden cronológico de las acciones (cada una sobre la fila bloqueada, con now()).
            'solicitud_cronologica_check' => 'solicitado_en IS NULL OR solicitado_en >= created_at',
            'aprobacion_cronologica_check' => 'aprobado_en IS NULL OR aprobado_en >= solicitado_en',
            'despacho_cronologico_check' => 'despachado_en IS NULL OR despachado_en >= aprobado_en',
            'recepcion_cronologica_check' => 'recibido_en IS NULL OR recibido_en >= despachado_en',
            'anulacion_cronologica_check' => 'anulado_en IS NULL OR (anulado_en >= created_at'
                .' AND (solicitado_en IS NULL OR anulado_en >= solicitado_en)'
                .' AND (aprobado_en IS NULL OR anulado_en >= aprobado_en))',
            'motivo_anulacion_check' => 'motivo_anulacion IS NULL OR char_length(btrim(motivo_anulacion)) > 0',
            // Coherencia estado ↔ acciones registradas (tabla de transiciones de RN-07; S-09: no se
            // anula lo despachado).
            'estado_coherente_check' => "(estado = 'BORRADOR' AND solicitado_por_id IS NULL AND aprobado_por_id IS NULL"
                .' AND despachado_por_id IS NULL AND recibido_por_id IS NULL AND anulado_por_id IS NULL)'
                ." OR (estado = 'SOLICITADO' AND solicitado_por_id IS NOT NULL AND aprobado_por_id IS NULL"
                .' AND despachado_por_id IS NULL AND recibido_por_id IS NULL AND anulado_por_id IS NULL)'
                ." OR (estado = 'APROBADO' AND solicitado_por_id IS NOT NULL AND aprobado_por_id IS NOT NULL"
                .' AND despachado_por_id IS NULL AND recibido_por_id IS NULL AND anulado_por_id IS NULL)'
                ." OR (estado = 'EN_TRANSITO' AND solicitado_por_id IS NOT NULL AND aprobado_por_id IS NOT NULL"
                .' AND despachado_por_id IS NOT NULL AND recibido_por_id IS NULL AND anulado_por_id IS NULL)'
                ." OR (estado IN ('RECIBIDO', 'RECIBIDO_PARCIAL') AND solicitado_por_id IS NOT NULL AND aprobado_por_id IS NOT NULL"
                .' AND despachado_por_id IS NOT NULL AND recibido_por_id IS NOT NULL AND anulado_por_id IS NULL)'
                ." OR (estado = 'ANULADO' AND anulado_por_id IS NOT NULL AND despachado_por_id IS NULL AND recibido_por_id IS NULL"
                .' AND (aprobado_por_id IS NULL OR solicitado_por_id IS NOT NULL))',
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE traslados ADD CONSTRAINT traslados_{$nombre} CHECK ({$condicion})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('traslados');
    }
};
