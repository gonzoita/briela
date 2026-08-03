<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recetas_corte')) return;

        Schema::create('recetas_corte', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150)->nullable();
            $table->foreignId('producto_insumo_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('producto_resultado_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad_insumo', 12, 3);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas_corte');
    }
};
