<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineas_op', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_produccion_id')
                  ->constrained('ordenes_produccion')
                  ->cascadeOnDelete();
            $table->enum('linea', ['puertas', 'paneleria', 'almacen']);
            $table->enum('estado', [
                'pendiente',
                'revision_material',
                'faltante_compras',
                'en_proceso',
                'checklist_pendiente',
                'completada',
            ])->default('pendiente');
            $table->boolean('tiene_faltantes')->default(false);
            $table->text('detalle_faltantes')->nullable();
            $table->json('checklist')->nullable();
            $table->boolean('checklist_aprobado')->default(false);
            $table->timestamp('inicio_proceso')->nullable();
            $table->timestamp('fin_proceso')->nullable();
            $table->integer('tiempo_minutos')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['orden_produccion_id', 'linea']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_op');
    }
};
