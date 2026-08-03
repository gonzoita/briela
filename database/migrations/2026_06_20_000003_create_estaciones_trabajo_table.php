<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estaciones_trabajo')) {
            return;
        }

        Schema::create('estaciones_trabajo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('color', 7)->default('#6B7280');
            $table->integer('capacidad_simultanea')->default(1);
            $table->boolean('activa')->default(true);
            $table->integer('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estaciones_trabajo');
    }
};
