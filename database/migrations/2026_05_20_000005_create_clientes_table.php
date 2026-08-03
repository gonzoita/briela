<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['persona', 'empresa'])->default('empresa');
            $table->string('tipo_identificacion', 10)->default('NIT');
            $table->string('numero_identificacion', 30)->nullable();
            $table->string('nombre', 200);
            $table->string('apellido', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('celular', 20)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
