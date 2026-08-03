<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->enum('estado', [
                'borrador', 'enviada', 'confirmada', 'recibida_parcial', 'recibida', 'cancelada',
            ])->default('borrador');
            $table->unsignedBigInteger('proveedor_id');
            $table->unsignedBigInteger('solicitud_id')->nullable();
            $table->unsignedBigInteger('creado_por');
            $table->date('fecha_entrega_esperada')->nullable();
            $table->date('fecha_recepcion')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('impuesto', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('condiciones')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('proveedor_id')->references('id')->on('proveedores');
            $table->foreign('solicitud_id')->references('id')->on('solicitudes_compra')->nullOnDelete();
            $table->foreign('creado_por')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra');
    }
};
