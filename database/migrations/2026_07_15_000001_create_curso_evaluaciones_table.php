<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('curso_evaluaciones')) return;

        Schema::create('curso_evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->unique()->constrained('cursos')->cascadeOnDelete();
            $table->string('nombre')->default('Evaluación final');
            $table->unsignedTinyInteger('nota_minima_aprobacion')->default(70);
            $table->unsignedInteger('intentos_permitidos')->nullable();
            $table->boolean('requiere_revision_manual')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curso_evaluaciones');
    }
};
