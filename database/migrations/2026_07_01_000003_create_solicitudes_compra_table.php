<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->enum('estado', [
                'borrador', 'pendiente', 'aprobada', 'rechazada', 'en_proceso', 'completada', 'cancelada',
            ])->default('borrador');
            $table->unsignedBigInteger('solicitado_por');
            $table->unsignedBigInteger('aprobado_por')->nullable();
            $table->timestamp('fecha_aprobacion')->nullable();
            $table->date('fecha_requerida')->nullable();
            $table->text('motivo')->nullable();
            $table->text('notas')->nullable();
            $table->unsignedBigInteger('op_id')->nullable();
            $table->timestamps();

            $table->foreign('solicitado_por')->references('id')->on('users');
            $table->foreign('aprobado_por')->references('id')->on('users')->nullOnDelete();
            $table->foreign('op_id')->references('id')->on('ops')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_compra');
    }
};
