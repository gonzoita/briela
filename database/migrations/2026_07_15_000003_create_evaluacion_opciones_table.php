<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluacion_opciones')) return;

        Schema::create('evaluacion_opciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_pregunta_id')->constrained('evaluacion_preguntas')->cascadeOnDelete();
            $table->string('texto');
            $table->boolean('es_correcta')->default(false);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_opciones');
    }
};
