<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contactos_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->string('apellido', 100)->nullable();
            $table->string('cargo', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('celular', 20)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contactos_cliente');
    }
};
