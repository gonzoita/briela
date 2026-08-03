<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('remisiones')) return;

        Schema::create('remisiones', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->enum('tipo', ['op', 'manual'])->default('op');
            $table->foreignId('op_id')->nullable()->constrained('ops')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->enum('estado', ['borrador', 'confirmada', 'en_camino', 'entregada', 'anulada'])->default('borrador');
            $table->date('fecha_remision')->nullable();
            $table->text('notas')->nullable();
            $table->string('transportista')->nullable();
            $table->string('celular_transportista')->nullable();
            $table->string('placa')->nullable();
            $table->decimal('costo_flete', 10, 2)->nullable();
            $table->dateTime('fecha_salida')->nullable();
            $table->dateTime('fecha_entrega')->nullable();
            $table->text('firma_despacho')->nullable();
            $table->text('firma_recibido')->nullable();
            $table->string('nombre_receptor')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remisiones');
    }
};
