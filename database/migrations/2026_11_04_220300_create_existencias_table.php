<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->unsignedBigInteger('lote_id');
            $table->integer('cantidad')->default(0);
            $table->timestamps();

            // RN-01-AC1: una existencia por bodega + producto + lote.
            $table->unique(['bodega_id', 'producto_id', 'lote_id'], 'existencias_bodega_producto_lote_unique');
            // RN-01-AC3: el lote debe pertenecer al producto de la existencia.
            $table->foreign(['lote_id', 'producto_id'], 'existencias_lote_producto_foreign')
                ->references(['id', 'producto_id'])->on('lotes')
                ->restrictOnDelete();
            $table->index('lote_id');
        });

        // RN-03-AC1: el stock nunca es negativo, ni por SQL directo.
        DB::statement('ALTER TABLE existencias ADD CONSTRAINT existencias_cantidad_check CHECK (cantidad >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('existencias');
    }
};
