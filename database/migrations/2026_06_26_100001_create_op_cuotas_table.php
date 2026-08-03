<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_cuotas')) return;

        Schema::create('op_cuotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('op_id');
            $table->foreign('op_id')->references('id')->on('ops')->cascadeOnDelete();
            $table->integer('numero_cuota');
            $table->string('concepto', 200);
            $table->decimal('valor', 14, 2);
            $table->date('fecha_vencimiento')->nullable();
            $table->enum('estado', ['pendiente', 'parcial', 'pagado'])->default('pendiente');
            $table->decimal('valor_pagado', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_cuotas');
    }
};
