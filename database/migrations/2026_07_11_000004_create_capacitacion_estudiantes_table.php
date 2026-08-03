<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capacitacion_estudiantes')) return;

        Schema::create('capacitacion_estudiantes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('tipo', ['contratista', 'cliente']);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('telefono', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacitacion_estudiantes');
    }
};
