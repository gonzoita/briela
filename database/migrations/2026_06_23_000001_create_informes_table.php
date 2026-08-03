<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('informes')) {
            return;
        }

        Schema::create('informes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->enum('fuente', ['ops', 'colaboradores', 'cotizaciones', 'pasos']);
            $table->json('campos');
            $table->json('filtros');
            $table->enum('tipo_grafica', ['barras', 'lineas', 'torta', 'ninguna'])->nullable();
            $table->boolean('publico')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informes');
    }
};
