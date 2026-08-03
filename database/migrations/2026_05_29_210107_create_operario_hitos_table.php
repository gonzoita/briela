<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_hitos')) return;

        Schema::create('operario_hitos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')->references('id')->on('operarios')->cascadeOnDelete();
            $table->string('nombre', 200);
            $table->enum('tipo', ['sistema', 'manual']);
            $table->decimal('meta_valor', 10, 2);
            $table->enum('meta_tipo', ['pasos', 'tiempo', 'ops']);
            $table->decimal('valor_bono', 15, 2);
            $table->tinyInteger('periodo_mes');
            $table->smallInteger('periodo_anio');
            $table->boolean('cumplido')->default(false);
            $table->timestamp('cumplido_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_hitos');
    }
};
