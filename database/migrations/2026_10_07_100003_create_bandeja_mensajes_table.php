<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada mensaje de una conversación de redes. Hermana de `whatsapp_mensajes`, con las mismas
 * columnas y los mismos nombres a propósito: la bandeja normaliza las dos a una sola forma, y
 * que coincidan hace que el adaptador sea una traducción y no una reescritura.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bandeja_mensajes')) {
            return;
        }

        Schema::create('bandeja_mensajes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('bandeja_conversacion_id')->constrained('bandeja_conversaciones')->cascadeOnDelete();

            // El id del mensaje o del comentario en la red. Es lo que evita guardar dos veces
            // lo mismo cuando el webhook se repite, que pasa seguido.
            $tabla->string('externo_id')->nullable();

            $tabla->enum('direccion', ['entrante', 'saliente']);
            $tabla->string('tipo', 30)->default('texto'); // texto | imagen | video | audio | documento | comentario
            $tabla->text('contenido')->nullable();

            // El adjunto ya descargado a nuestro servidor. Los enlaces que da Meta vencen en
            // horas: guardar solo la URL deja un historial de imágenes rotas.
            $tabla->foreignId('archivo_id')->nullable()->constrained('archivos')->nullOnDelete();

            $tabla->string('estado')->nullable(); // enviado | entregado | leido | fallido
            $tabla->text('error')->nullable();
            $tabla->boolean('es_echo')->default(false); // lo escribió alguien desde la app de la red
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamps();

            $tabla->index(['bandeja_conversacion_id', 'id'], 'bandeja_msj_hilo_idx');
            $tabla->index('externo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bandeja_mensajes');
    }
};
