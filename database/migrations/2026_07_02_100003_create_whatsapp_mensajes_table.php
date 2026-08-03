<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_mensajes')) return;

        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversacion_id')->constrained('whatsapp_conversaciones')->cascadeOnDelete();
            $table->string('wa_message_id')->nullable(); // ID del mensaje en Meta
            $table->enum('direccion', ['entrante', 'saliente']);
            $table->string('tipo')->default('texto'); // texto | imagen | documento | audio
            $table->text('contenido')->nullable();
            $table->string('url_media')->nullable();
            $table->string('estado')->nullable(); // enviado | entregado | leido | fallido
            $table->boolean('es_echo')->default(false); // true si vino de smb_message_echoes (Coexistencia)
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
    }
};
