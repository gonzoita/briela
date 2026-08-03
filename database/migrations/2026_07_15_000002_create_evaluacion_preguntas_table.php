<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluacion_preguntas')) return;

        Schema::create('evaluacion_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_evaluacion_id')->constrained('curso_evaluaciones')->cascadeOnDelete();
            $table->text('enunciado');
            $table->enum('tipo', ['opcion_multiple', 'abierta']);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_preguntas');
    }
};
