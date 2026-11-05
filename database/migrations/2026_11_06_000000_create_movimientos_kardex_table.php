<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RN-06: kardex completo e inmutable. Solo INSERT; las correcciones son movimientos AJUSTE con motivo.
 *
 * bodega_id / producto_id / lote_id se copian de la existencia para filtrar e indexar sin JOIN
 * (RN-06-AC8). No pueden divergir: FK compuesta (existencia_id, bodega_id, producto_id, lote_id)
 * contra un UNIQUE de existencias sobre las mismas columnas.
 *
 * Documento origen polimórfico (documento_tipo + documento_id, sin FK porque apunta a tablas
 * distintas): obligatorio para SALIDA_DISPENSACION ('dispensacion') y *_TRASLADO ('traslado'); nulo
 * para ENTRADA (no hay documento de compra) y AJUSTE (el propio movimiento es el documento).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE existencias ADD CONSTRAINT existencias_id_terna_unique UNIQUE (id, bodega_id, producto_id, lote_id)');

        Schema::create('movimientos_kardex', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30);
            $table->unsignedBigInteger('existencia_id');
            $table->unsignedBigInteger('bodega_id');
            $table->unsignedBigInteger('producto_id');
            $table->unsignedBigInteger('lote_id');
            // Mismo tipo que existencias.cantidad (integer).
            $table->integer('cantidad');
            $table->integer('saldo_anterior');
            $table->integer('saldo_posterior');
            $table->string('motivo', 500)->nullable();
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $table->string('documento_tipo', 30)->nullable();
            $table->unsignedBigInteger('documento_id')->nullable();
            // Sin updated_at: un movimiento nunca cambia. Sin default: lo fija la aplicación en su zona.
            $table->timestamp('created_at');

            $table->foreign(['existencia_id', 'bodega_id', 'producto_id', 'lote_id'], 'movimientos_kardex_existencia_foreign')
                ->references(['id', 'bodega_id', 'producto_id', 'lote_id'])->on('existencias')
                ->restrictOnDelete();

            // RN-06-AC8: orden created_at, id con y sin filtros.
            $table->index(['created_at', 'id'], 'movimientos_kardex_created_index');
            $table->index(['existencia_id', 'created_at', 'id'], 'movimientos_kardex_existencia_created_index');
            $table->index(['producto_id', 'created_at', 'id'], 'movimientos_kardex_producto_created_index');
            $table->index(['lote_id', 'created_at', 'id'], 'movimientos_kardex_lote_created_index');
            $table->index(['bodega_id', 'created_at', 'id'], 'movimientos_kardex_bodega_created_index');
            $table->index(['documento_tipo', 'documento_id'], 'movimientos_kardex_documento_index');
            $table->index('usuario_id');
        });

        $checks = [
            'tipo_check' => "tipo IN ('ENTRADA', 'SALIDA_DISPENSACION', 'SALIDA_TRASLADO', 'ENTRADA_TRASLADO', 'AJUSTE')",
            'cantidad_check' => 'cantidad <> 0',
            'saldo_anterior_check' => 'saldo_anterior >= 0',
            'saldo_posterior_check' => 'saldo_posterior >= 0',
            // En bigint: la suma de dos integer no desborda al evaluar el CHECK.
            'saldo_cuadra_check' => 'saldo_posterior::bigint = saldo_anterior::bigint + cantidad::bigint',
            'signo_tipo_check' => "(tipo IN ('ENTRADA', 'ENTRADA_TRASLADO') AND cantidad > 0)"
                ." OR (tipo IN ('SALIDA_DISPENSACION', 'SALIDA_TRASLADO') AND cantidad < 0)"
                ." OR tipo = 'AJUSTE'",
            'motivo_ajuste_check' => "tipo <> 'AJUSTE' OR (motivo IS NOT NULL AND char_length(btrim(motivo)) >= 10)",
            // `documento_tipo IS NOT NULL` explícito: con documento_tipo NULL, `documento_tipo = '…'` da
            // NULL y un CHECK que evalúa a NULL se ACEPTA; así cada cláusula da FALSE y no TRUE/NULL.
            'documento_check' => "(tipo = 'SALIDA_DISPENSACION' AND documento_tipo IS NOT NULL AND documento_tipo = 'dispensacion' AND documento_id IS NOT NULL)"
                ." OR (tipo IN ('SALIDA_TRASLADO', 'ENTRADA_TRASLADO') AND documento_tipo IS NOT NULL AND documento_tipo = 'traslado' AND documento_id IS NOT NULL)"
                ." OR (tipo IN ('ENTRADA', 'AJUSTE') AND documento_tipo IS NULL AND documento_id IS NULL)",
        ];

        foreach ($checks as $nombre => $condicion) {
            DB::statement("ALTER TABLE movimientos_kardex ADD CONSTRAINT movimientos_kardex_{$nombre} CHECK ({$condicion})");
        }

        // RN-06-AC4 S-40: ni siquiera con SQL directo se edita, borra o vacía el kardex. OR REPLACE: migrate:fresh
        // borra tablas pero no funciones.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION movimientos_kardex_inmutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'movimientos_kardex es inmutable: % no permitido (use un movimiento AJUSTE)', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;

            CREATE TRIGGER movimientos_kardex_inmutable
                BEFORE UPDATE OR DELETE ON movimientos_kardex
                FOR EACH ROW EXECUTE FUNCTION movimientos_kardex_inmutable();

            -- S-40: TRUNCATE (directo o por TRUNCATE existencias CASCADE) no dispara los triggers de
            -- fila; este, de sentencia, también lo rechaza. migrate:fresh hace DROP, no TRUNCATE.
            CREATE TRIGGER movimientos_kardex_sin_truncate
                BEFORE TRUNCATE ON movimientos_kardex
                FOR EACH STATEMENT EXECUTE FUNCTION movimientos_kardex_inmutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS movimientos_kardex_sin_truncate ON movimientos_kardex');
        DB::unprepared('DROP TRIGGER IF EXISTS movimientos_kardex_inmutable ON movimientos_kardex');
        Schema::dropIfExists('movimientos_kardex');
        DB::unprepared('DROP FUNCTION IF EXISTS movimientos_kardex_inmutable()');
        DB::statement('ALTER TABLE existencias DROP CONSTRAINT IF EXISTS existencias_id_terna_unique');
    }
};
