<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hilos internos pegados a un documento.
 *
 * La idea no es competir con WhatsApp para lo urgente —la gente ya lo tiene
 * abierto—, sino que la discusión sobre una OP viva DENTRO de esa OP y deje
 * rastro. "¿Por qué se cambió este precio?" se responde mirando el hilo, no
 * buscando en el celular de alguien.
 *
 * Es polimórfica: el mismo hilo sirve para una OP, una cotización, un cliente
 * o lo que venga después, sin una tabla por módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comentarios', function (Blueprint $table) {
            $table->id();

            // A qué documento pertenece (Op, Cotizacion, Cliente...).
            $table->morphs('comentable');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('contenido');

            // Un hilo no es solo charla: también sirve para pedir algo o
            // dejar una tarea, y eso necesita estado para no perderse.
            $table->enum('tipo', ['comentario', 'solicitud', 'tarea'])->default('comentario');
            $table->enum('estado', ['pendiente', 'resuelta', 'rechazada'])->nullable();

            $table->foreignId('asignado_a')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha_limite')->nullable();

            $table->timestamp('resuelto_at')->nullable();
            $table->foreignId('resuelto_por')->nullable()->constrained('users')->nullOnDelete();

            // A quiénes se mencionó con @, para no volver a analizar el texto.
            $table->json('mencionados')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // El listado siempre pide "los de este documento, en orden".
            $table->index(['comentable_type', 'comentable_id', 'created_at'], 'comentarios_documento_idx');
            // Y la bandeja personal pide "lo que me asignaron y sigue abierto".
            $table->index(['asignado_a', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comentarios');
    }
};
