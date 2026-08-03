<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('template_trabajo_pasos')) return;

        Schema::create('template_trabajo_pasos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->foreign('template_id')->references('id')->on('templates_trabajo')->cascadeOnDelete();
            $table->string('nombre', 200);
            $table->text('descripcion');
            $table->decimal('peso_porcentaje', 5, 2);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_trabajo_pasos');
    }
};
