<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantilla_campos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plantilla_id')->constrained('plantillas_ensamble')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('etiqueta', 150);
            $table->enum('tipo', ['texto', 'numero', 'decimal', 'select', 'boolean', 'checkbox'])->default('decimal');
            $table->json('opciones')->nullable();
            $table->string('valor_defecto', 255)->nullable();
            $table->boolean('requerido')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_campos');
    }
};
