<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El plazo de entrega que se le promete al cliente, en días hábiles. Hasta ahora vivía solo en
 * el texto de las condiciones, así que la orden nacía sin fecha de entrega: sin urgencia en
 * planta y con «Sin fecha» en la lista de lo que hay que remisionar. Solo agrega una columna nula.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotizaciones') && ! Schema::hasColumn('cotizaciones', 'dias_entrega')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->unsignedSmallInteger('dias_entrega')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante: no se borran columnas de una instalación.
    }
};
