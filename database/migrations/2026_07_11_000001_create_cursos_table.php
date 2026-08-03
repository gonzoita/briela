<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cursos')) return;

        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();
            $table->string('categoria', 100)->nullable();
            $table->enum('publico_objetivo', ['colaborador', 'contratista', 'cliente', 'todos'])->default('todos');
            $table->boolean('obligatorio')->default(false);
            $table->boolean('activo')->default(true);
            $table->string('imagen_portada')->nullable();
            $table->integer('puntos_otorga')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
