<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluacion_intentos')) return;

        Schema::create('evaluacion_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('inscripciones')->cascadeOnDelete();
            $table->foreignId('curso_evaluacion_id')->constrained('curso_evaluaciones')->cascadeOnDelete();
            $table->integer('numero_intento');
            $table->json('respuestas');
            $table->decimal('nota', 5, 2)->nullable();
            $table->boolean('aprobado')->nullable();
            $table->enum('estado', ['pendiente_revision', 'calificado'])->default('calificado');
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revisado_at')->nullable();
            $table->text('notas_revisor')->nullable();
            $table->dateTime('completado_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluacion_intentos');
    }
};
