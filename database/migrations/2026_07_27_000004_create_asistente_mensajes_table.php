<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial del asistente, por usuario.
 *
 * Antes la conversación vivía solo en memoria del navegador: al recargar la
 * página se perdía todo, y lo que se había preguntado ayer no existía.
 *
 * Se guarda por usuario y no por empresa porque las preguntas suelen incluir
 * cifras que dependen de los permisos de quien pregunta: el chat de un
 * vendedor no debería ser visible para otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistente_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('rol', ['usuario', 'asistente']);
            $table->text('contenido');
            // Qué consultas se usaron para responder, para poder mostrar la
            // fuente debajo del mensaje igual que en vivo.
            $table->string('consulta', 255)->nullable();
            $table->timestamps();

            // El historial siempre se lee "los últimos N de este usuario".
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistente_mensajes');
    }
};
