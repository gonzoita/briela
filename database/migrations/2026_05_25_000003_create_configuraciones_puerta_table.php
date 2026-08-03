<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_puerta', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('tipo_puerta', 50);
            $table->string('tipo_sello', 50)->nullable();
            $table->string('temperatura', 10);
            $table->decimal('ancho_vano', 5, 3);
            $table->decimal('alto_vano', 5, 3);
            $table->string('tipo_corredera', 10)->nullable();
            $table->boolean('sin_llave')->default(false);
            $table->boolean('palanca')->default(false);
            $table->boolean('visor')->default(false);
            $table->json('desglose');
            $table->json('precios_resultado');
            $table->foreignId('creado_por')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_puerta');
    }
};
