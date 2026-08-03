<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invitaciones_capacitacion')) return;

        Schema::create('invitaciones_capacitacion', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('email');
            $table->enum('tipo', ['contratista', 'cliente']);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('nombre_sugerido')->nullable();
            $table->foreignId('invitado_por')->constrained('users');
            $table->dateTime('expira_at');
            $table->dateTime('usado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones_capacitacion');
    }
};
