<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('progreso_lecciones')) return;

        Schema::create('progreso_lecciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('inscripciones')->cascadeOnDelete();
            $table->foreignId('curso_leccion_id')->constrained('curso_lecciones')->cascadeOnDelete();
            $table->boolean('completado')->default(false);
            $table->dateTime('completado_at')->nullable();
            $table->timestamps();

            $table->unique(['inscripcion_id', 'curso_leccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progreso_lecciones');
    }
};
