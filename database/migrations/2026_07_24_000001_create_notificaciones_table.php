<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Centro de notificaciones internas (la "campanita"). Cada fila es un aviso
// para un usuario del sistema, disparado por un evento (OP nueva, trabajo
// asignado, entrega por vencer, etc.). No confundir con los webhooks de
// GoHighLevel, que son avisos EXTERNOS al cliente por WhatsApp/email.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tipo');                 // ej: op_nueva, trabajo_asignado
            $table->string('titulo');
            $table->text('mensaje')->nullable();
            $table->string('url')->nullable();       // a dónde lleva al hacer clic
            $table->string('icono')->nullable();     // nombre de ícono para la UI
            $table->string('color')->nullable();     // color/acento para la UI
            $table->boolean('leida')->default(false);
            $table->timestamp('leida_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'leida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
