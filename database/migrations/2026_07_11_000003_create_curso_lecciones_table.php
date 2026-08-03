<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('curso_lecciones')) return;

        Schema::create('curso_lecciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_modulo_id')->constrained('curso_modulos')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->enum('tipo', ['video_drive', 'video_externo', 'texto', 'pdf']);
            $table->text('contenido');
            $table->integer('duracion_minutos')->nullable();
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curso_lecciones');
    }
};
