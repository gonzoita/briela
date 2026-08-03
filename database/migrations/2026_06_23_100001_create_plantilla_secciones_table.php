<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plantilla_secciones')) {
            Schema::create('plantilla_secciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plantilla_id')
                      ->constrained('plantillas_ensamble')
                      ->cascadeOnDelete();
                $table->string('nombre', 150);
                $table->integer('orden')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plantilla_secciones');
    }
};
