<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_trabajo_pasos')) return;

        Schema::create('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('op_item_trabajo_id');
            $table->foreign('op_item_trabajo_id')->references('id')->on('op_item_trabajos')->cascadeOnDelete();
            $table->unsignedBigInteger('template_paso_id')->nullable();
            $table->foreign('template_paso_id')->references('id')->on('template_trabajo_pasos')->nullOnDelete();
            $table->string('nombre', 200);
            $table->text('descripcion_resuelta');
            $table->decimal('peso_porcentaje', 5, 2);
            $table->boolean('completado')->default(false);
            $table->timestamp('completado_at')->nullable();
            $table->unsignedBigInteger('operario_id')->nullable();
            $table->foreign('operario_id')->references('id')->on('operarios')->nullOnDelete();
            $table->integer('tiempo_minutos')->nullable();
            $table->boolean('es_extra')->default(false);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_item_trabajo_pasos');
    }
};
