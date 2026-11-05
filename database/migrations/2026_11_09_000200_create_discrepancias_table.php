<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-07-AC6/AC12: diferencia entre lo despachado y lo recibido de un ítem. Nace PENDIENTE al
 * recibir y un regente la resuelve una vez: PERDIDA (sin stock) o REINTEGRO_ORIGEN /
 * ENTREGA_DESTINO (AJUSTE positivo, cuyo movimiento queda referenciado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discrepancias', function (Blueprint $table) {
            $table->id();
            // Una discrepancia por ítem.
            $table->foreignId('traslado_item_id')->unique()->constrained('traslado_items')->restrictOnDelete();
            $table->integer('cantidad');
            $table->string('estado', 20);
            $table->string('tipo_resolucion', 20)->nullable();
            $table->string('motivo', 500)->nullable();
            $table->foreignId('resuelto_por_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resuelto_en')->nullable();
            $table->foreignId('movimiento_kardex_id')->nullable()->unique()->constrained('movimientos_kardex')->restrictOnDelete();
            $table->timestamps();

            $table->index('estado');
        });

        $checks = [
            'cantidad_check' => 'cantidad > 0',
            'estado_check' => "estado IN ('PENDIENTE', 'RESUELTA')",
            'tipo_resolucion_check' => "tipo_resolucion IS NULL OR tipo_resolucion IN ('PERDIDA', 'REINTEGRO_ORIGEN', 'ENTREGA_DESTINO')",
            'motivo_check' => 'motivo IS NULL OR char_length(btrim(motivo)) > 0',
            'estado_coherente_check' => "(estado = 'PENDIENTE' AND tipo_resolucion IS NULL AND motivo IS NULL"
                .' AND resuelto_por_id IS NULL AND resuelto_en IS NULL AND movimiento_kardex_id IS NULL)'
                ." OR (estado = 'RESUELTA' AND tipo_resolucion IS NOT NULL AND motivo IS NOT NULL"
                .' AND resuelto_por_id IS NOT NULL AND resuelto_en IS NOT NULL'
                ." AND (tipo_resolucion = 'PERDIDA') = (movimiento_kardex_id IS NULL))",
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE discrepancias ADD CONSTRAINT discrepancias_{$nombre} CHECK ({$condicion})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancias');
    }
};
