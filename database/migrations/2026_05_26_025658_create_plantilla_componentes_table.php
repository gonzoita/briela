<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_ensamble')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('etiqueta', 200)->nullable();
            $table->string('formula', 500)->default('0');
            $table->string('condicion', 500)->nullable();
            $table->string('unidad', 20)->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->string('notas', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_componentes');
    }
};
