<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RN-10-AC7: POST /pacientes/buscar admite el documento sin `tipo_documento`. El índice único
 * (tipo_documento, numero_documento) no sirve para filtrar solo por la segunda columna, así que esa
 * búsqueda recorrería la tabla entera: índice propio sobre numero_documento.
 */
return new class extends Migration
{
    public const INDICE = 'pacientes_numero_documento_index';

    public function up(): void
    {
        Schema::table('pacientes', function (Blueprint $table): void {
            $table->index('numero_documento', self::INDICE);
        });
    }

    public function down(): void
    {
        Schema::table('pacientes', function (Blueprint $table): void {
            $table->dropIndex(self::INDICE);
        });
    }
};
