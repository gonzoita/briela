<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('archivos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_original');
            $table->string('nombre_archivo');
            $table->string('ruta');
            $table->string('tipo_mime');
            $table->string('extension', 10);
            $table->unsignedBigInteger('tamano');
            $table->enum('categoria', ['plano', 'foto_calidad', 'documento', 'otro'])->default('otro');
            $table->morphs('archivable');
            $table->foreignId('subido_por')->constrained('users');
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archivos');
    }
};
