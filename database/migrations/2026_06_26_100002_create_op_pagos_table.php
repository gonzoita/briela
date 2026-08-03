<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_pagos')) return;

        Schema::create('op_pagos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('op_id');
            $table->foreign('op_id')->references('id')->on('ops')->cascadeOnDelete();
            $table->unsignedBigInteger('cuota_id')->nullable();
            $table->foreign('cuota_id')->references('id')->on('op_cuotas')->nullOnDelete();
            $table->string('numero_recibo', 20)->unique();
            $table->decimal('valor', 14, 2);
            $table->enum('medio_pago', ['efectivo', 'transferencia', 'cheque']);
            $table->date('fecha_pago');
            $table->string('referencia', 200)->nullable();
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->foreign('registrado_por')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_pagos');
    }
};
