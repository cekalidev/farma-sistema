<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-89 GET /dispensaciones: orden (created_at, id) con filtro opcional por estado.
 *  - (estado, created_at, id) sirve al panel de pendientes del regente (filtro + orden) y sustituye
 *    al índice simple de `estado`, que cubre como prefijo (CalculadorPendiente solo filtra estado
 *    tras el join por id, así que nada necesita el simple por separado).
 *  - (created_at, id) sirve al listado sin filtros. Con bodega_id ya existe (bodega_id, created_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispensaciones', function (Blueprint $table) {
            $table->index(['estado', 'created_at', 'id'], 'dispensaciones_estado_listado_index');
            $table->index(['created_at', 'id'], 'dispensaciones_listado_index');
            $table->dropIndex('dispensaciones_estado_index');
        });
    }

    public function down(): void
    {
        Schema::table('dispensaciones', function (Blueprint $table) {
            $table->index('estado', 'dispensaciones_estado_index');
            $table->dropIndex('dispensaciones_listado_index');
            $table->dropIndex('dispensaciones_estado_listado_index');
        });
    }
};
