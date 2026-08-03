<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('equipos_mantenimiento')) {
            Schema::create('equipos_mantenimiento', function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->string('codigo')->unique()->nullable();
                $table->string('tipo')->nullable(); // compresor, evaporador, condensador, puerta, panel, otro
                $table->string('marca')->nullable();
                $table->string('modelo')->nullable();
                $table->string('serial')->nullable();
                $table->date('fecha_instalacion')->nullable();
                $table->date('proxima_revision')->nullable();
                $table->integer('frecuencia_dias')->default(90); // cada cuántos días se hace mantenimiento
                $table->string('ubicacion')->nullable();
                $table->string('responsable')->nullable(); // nombre del encargado
                $table->text('observaciones')->nullable();
                $table->enum('estado', ['activo', 'en_mantenimiento', 'fuera_servicio'])->default('activo');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos_mantenimiento');
    }
};
