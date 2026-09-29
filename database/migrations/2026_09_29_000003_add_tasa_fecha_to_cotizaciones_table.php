<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué día es la tasa de una cotización en otra moneda.
 *
 * La cotización ya guardaba la tasa; faltaba la fecha. Sin ella, el cliente lee «tasa
 * 4.123» y no sabe si es la de hoy o la de hace tres semanas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            if (! Schema::hasColumn('cotizaciones', 'tasa_fecha')) {
                $table->date('tasa_fecha')->nullable()->after('tasa_cambio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('tasa_fecha');
        });
    }
};
