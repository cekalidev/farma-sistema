<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** S-14: stock mínimo por bodega + producto (RN-11). */
    public function up(): void
    {
        Schema::create('stock_minimos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bodega_id')->constrained('bodegas')->restrictOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->integer('cantidad_minima');
            $table->timestamps();

            $table->unique(['bodega_id', 'producto_id'], 'stock_minimos_bodega_producto_unique');
        });

        DB::statement('ALTER TABLE stock_minimos ADD CONSTRAINT stock_minimos_cantidad_minima_check CHECK (cantidad_minima >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_minimos');
    }
};
