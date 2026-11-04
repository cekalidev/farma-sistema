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
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // BASE-AC5: valores de App\Enums\Rol, respaldados por CHECK.
            $table->string('rol', 30);
            $table->rememberToken();
            $table->timestamps();
        });

        // Valores fijados aquí (no leídos del enum) para que la migración sea inmutable.
        // BASE-AC5: no pueden coexistir 'Ana@x.test' y 'ana@x.test' (el modelo ya guarda en
        // minúsculas; esto lo respalda ante INSERT directo). El login busca con
        // `lower(email) = ?` (User::scopePorCorreo) para usar este índice.
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('auxiliar_farmacia', 'regente_farmacia', 'medico', 'auditor', 'admin'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
