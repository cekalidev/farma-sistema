<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Unicidad sin distinguir mayúsculas: índice único sobre lower(email), más abajo.
            $table->string('email');
            $table->string('password');
            // BASE-AC5: valores de App\Enums\Rol, respaldados por CHECK.
            $table->string('rol', 30);
            $table->timestamps();
        });

        // Valores fijados aquí (no leídos del enum) para que la migración sea inmutable.
        // BASE-AC5: no pueden coexistir 'Ana@x.test' y 'ana@x.test' (el modelo ya guarda en
        // minúsculas; esto lo respalda ante INSERT directo). El login busca con
        // `lower(email) = ?` (User::scopePorCorreo) para usar este índice.
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
