<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_trabajo_paso_operarios')) return;

        Schema::create('op_item_trabajo_paso_operarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('op_item_trabajo_paso_id');
            $table->foreign('op_item_trabajo_paso_id')
                ->references('id')->on('op_item_trabajo_pasos')->cascadeOnDelete();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')
                ->references('id')->on('operarios')->cascadeOnDelete();
            $table->integer('tiempo_minutos')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_item_trabajo_paso_operarios');
    }
};
