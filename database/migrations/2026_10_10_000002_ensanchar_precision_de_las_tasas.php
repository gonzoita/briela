<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las tasas de cambio con ocho decimales.
 *
 * Con una base fuerte (dólar) y una moneda débil (peso), una unidad vale 0,00024 de la base:
 * con cuatro decimales eso se redondeaba a 0,0002 y todo costo en esa moneda salía 20 % errado.
 * Ensanchar una columna decimal no cambia ningún valor ya guardado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasas_cambio')) {
            DB::statement('ALTER TABLE tasas_cambio MODIFY valor DECIMAL(20,8) NOT NULL');
        }

        if (Schema::hasColumn('cotizaciones', 'tasa_cambio')) {
            DB::statement('ALTER TABLE cotizaciones MODIFY tasa_cambio DECIMAL(20,8) NOT NULL DEFAULT 1');
        }
    }

    public function down(): void
    {
        // Estrechar perdería decimales; no se deshace.
    }
};
