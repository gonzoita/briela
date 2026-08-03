<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_produccion', function (Blueprint $table) {
            $table->id();
            $table->string('numero_op')->unique();
            $table->string('cliente', 200);
            $table->string('vendedor', 200);
            $table->text('descripcion')->nullable();
            $table->text('planos_medidas')->nullable();
            $table->string('archivo_plano')->nullable();
            $table->decimal('anticipo', 12, 2)->default(0);
            $table->enum('estado', [
                'borrador',
                'pendiente_verificacion',
                'en_produccion',
                'control_calidad',
                'reproceso',
                'empaque',
                'despachada',
                'cerrada',
                'rechazada',
            ])->default('borrador');
            $table->text('motivo_rechazo')->nullable();
            $table->text('observaciones_calidad')->nullable();
            $table->string('numero_remision')->nullable();
            $table->timestamp('fecha_anticipo')->nullable();
            $table->timestamp('fecha_verificacion')->nullable();
            $table->timestamp('fecha_inicio_produccion')->nullable();
            $table->timestamp('fecha_despacho')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_produccion');
    }
};
