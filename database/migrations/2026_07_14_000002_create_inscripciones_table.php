<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inscripciones')) return;

        Schema::create('inscripciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inscribible_id');
            $table->string('inscribible_type');
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();
            $table->boolean('obligatorio')->default(false);
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->date('fecha_limite')->nullable();
            $table->enum('estado', ['pendiente', 'en_progreso', 'completado', 'aprobado'])->default('pendiente');
            $table->dateTime('iniciado_at')->nullable();
            $table->dateTime('completado_at')->nullable();
            $table->timestamps();

            $table->unique(['inscribible_id', 'inscribible_type', 'curso_id'], 'inscripciones_inscribible_curso_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscripciones');
    }
};
