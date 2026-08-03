<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nit')->nullable();
            $table->string('contacto')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('direccion')->nullable();
            $table->enum('tipo', ['materia_prima', 'insumos', 'mixto'])->default('mixto');
            $table->boolean('activo')->default(true);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
