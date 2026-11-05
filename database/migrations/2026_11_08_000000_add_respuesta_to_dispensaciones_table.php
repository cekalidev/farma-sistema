<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-09 (AC1, AC8): respuesta original de la creación (status y cuerpo) para reproducirla en un
 * reintento con la misma Idempotency-Key, aunque la dispensación cambie después.
 *
 * Migración nueva y no edición de create_dispensaciones_table: esa ya está confirmada en el
 * historial (v0.5.0) y una BD migrada no volvería a ejecutarla.
 *
 * `respuesta_cuerpo` es `json` (texto validado) y no `jsonb`: jsonb reordena las claves y el
 * reintento debe devolver el mismo cuerpo, byte a byte. Solo se guardan respuestas de éxito (S-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensaciones', function (Blueprint $table) {
            $table->smallInteger('respuesta_status')->nullable();
            $table->json('respuesta_cuerpo')->nullable();
        });

        $checks = [
            'respuesta_completa_check' => '(respuesta_status IS NULL) = (respuesta_cuerpo IS NULL)',
            // Sin clave no hay nada que reproducir.
            'respuesta_con_clave_check' => 'respuesta_status IS NULL OR idempotency_key IS NOT NULL',
            'respuesta_status_check' => 'respuesta_status IS NULL OR respuesta_status BETWEEN 200 AND 299',
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE dispensaciones ADD CONSTRAINT dispensaciones_{$nombre} CHECK ({$condicion})");
        }
    }

    public function down(): void
    {
        Schema::table('dispensaciones', function (Blueprint $table) {
            $table->dropColumn(['respuesta_status', 'respuesta_cuerpo']);
        });
    }
};
