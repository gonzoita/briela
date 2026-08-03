<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operario_permisos')) return;

        Schema::create('operario_permisos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operario_id');
            $table->foreign('operario_id')->references('id')->on('operarios')->cascadeOnDelete();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->text('motivo')->nullable();
            $table->boolean('aprobado')->default(false);
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->foreign('aprobado_por')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operario_permisos');
    }
};
