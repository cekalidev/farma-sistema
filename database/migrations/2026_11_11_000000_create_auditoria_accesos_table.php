<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-10: bitácora de accesos a pacientes (AC5/AC6) y de operaciones sensibles (AC9). Solo INSERT,
 * y solo desde App\Services\Auditoria\Bitacora. Sin datos personales: ids, ruta (las rutas de
 * paciente solo llevan ids; el documento viaja en el cuerpo, AC7), IP y correlation id.
 *
 * Inmutable como el kardex (AC10, S-40): trigger BEFORE UPDATE OR DELETE por fila y BEFORE
 * TRUNCATE por sentencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_accesos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('paciente_id')->nullable()->constrained('pacientes')->restrictOnDelete();
            $table->string('accion', 40);
            $table->string('resultado', 10);
            // Operaciones sensibles (AC9): entidad + id. En consultas de paciente, null salvo el
            // detalle de dispensación.
            $table->string('entidad', 30)->nullable();
            $table->unsignedBigInteger('entidad_id')->nullable();
            // Contexto HTTP: null si la acción se ejecuta fuera de una petición (consola, tests de servicio).
            $table->string('ruta', 255)->nullable();
            $table->string('metodo', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('correlation_id', 64);
            // Sin updated_at: una fila nunca cambia. Sin default: lo fija la aplicación.
            $table->timestamp('created_at');

            $table->index(['created_at', 'id'], 'auditoria_accesos_created_index');
            $table->index(['paciente_id', 'created_at', 'id'], 'auditoria_accesos_paciente_created_index');
            $table->index(['usuario_id', 'created_at', 'id'], 'auditoria_accesos_usuario_created_index');
            $table->index(['entidad', 'entidad_id'], 'auditoria_accesos_entidad_index');
            $table->index('correlation_id');
        });

        $acciones = "'PACIENTE_CONSULTAR', 'PACIENTE_BUSCAR', 'PACIENTE_PRESCRIPCIONES', 'DISPENSACION_CONSULTAR',"
            ." 'DISPENSACION_AUTORIZAR', 'DISPENSACION_RECHAZAR', 'INVENTARIO_AJUSTAR', 'TRASLADO_APROBAR',"
            ." 'TRASLADO_ANULAR', 'DISCREPANCIA_RESOLVER'";

        $checks = [
            'resultado_check' => "resultado IN ('PERMITIDO', 'DENEGADO')",
            'accion_check' => "accion IN ({$acciones})",
            'entidad_check' => "entidad IS NULL OR entidad IN ('dispensacion', 'movimiento_kardex', 'traslado', 'discrepancia')",
            // Entidad e id van juntos.
            'entidad_id_check' => '(entidad IS NULL) = (entidad_id IS NULL)',
            'correlation_id_check' => "correlation_id <> ''",
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE auditoria_accesos ADD CONSTRAINT auditoria_accesos_{$nombre} CHECK ({$condicion})");
        }

        // OR REPLACE: migrate:fresh borra tablas pero no funciones.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION auditoria_accesos_inmutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'auditoria_accesos es inmutable: % no permitido', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER auditoria_accesos_inmutable
                BEFORE UPDATE OR DELETE ON auditoria_accesos
                FOR EACH ROW EXECUTE FUNCTION auditoria_accesos_inmutable();

            -- TRUNCATE (directo o en cascada desde users/pacientes) no dispara triggers de fila.
            CREATE TRIGGER auditoria_accesos_sin_truncate
                BEFORE TRUNCATE ON auditoria_accesos
                FOR EACH STATEMENT EXECUTE FUNCTION auditoria_accesos_inmutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS auditoria_accesos_sin_truncate ON auditoria_accesos');
        DB::unprepared('DROP TRIGGER IF EXISTS auditoria_accesos_inmutable ON auditoria_accesos');
        Schema::dropIfExists('auditoria_accesos');
        DB::unprepared('DROP FUNCTION IF EXISTS auditoria_accesos_inmutable()');
    }
};
