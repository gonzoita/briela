<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_bonos')) return;

        Schema::create('operario_bonos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')->references('id')->on('operarios')->cascadeOnDelete();
            $table->tinyInteger('periodo_mes');
            $table->smallInteger('periodo_anio');
            $table->decimal('pasos_valor', 15, 2)->default(0);
            $table->decimal('hitos_valor', 15, 2)->default(0);
            $table->decimal('extras_valor', 15, 2)->default(0);
            $table->decimal('penalizaciones', 15, 2)->default(0);
            $table->decimal('total_bono', 15, 2)->default(0);
            $table->timestamp('calculado_at')->nullable();
            $table->json('detalle')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_bonos');
    }
};
