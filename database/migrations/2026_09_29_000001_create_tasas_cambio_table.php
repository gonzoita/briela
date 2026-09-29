<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tasa de cambio de cada moneda, un registro por día.
 *
 * Se guarda la historia y no solo la de hoy: una cotización dice «a TRM del 29 de
 * septiembre», y esa cifra tiene que poder verse después aunque la tasa haya cambiado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasas_cambio')) {
            return;
        }

        Schema::create('tasas_cambio', function (Blueprint $table) {
            $table->id();
            $table->string('moneda', 3);
            $table->date('fecha');
            // Pesos por una unidad de la moneda: 4123.4500 para el dólar.
            $table->decimal('valor', 14, 4);
            // De dónde salió: 'superfinanciera', 'bce', 'manual'.
            $table->string('fuente', 30);
            $table->timestamps();

            $table->unique(['moneda', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasas_cambio');
    }
};
