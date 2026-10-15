<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El mínimo y el máximo de stock eran `int`: un material que se mide en metros o en kilos con un
 * mínimo de 2,5 guardaba 2, y el formulario —que acepta tres decimales— no avisaba. Se amplían a
 * `decimal(14,3)`, la misma precisión que las existencias (`producto_stock.cantidad`).
 *
 * Solo ensancha: todo entero cabe y conserva su valor. Sigue siendo `NOT NULL DEFAULT 0`; cero en
 * el máximo significa «sin máximo», igual que cero en el mínimo significa «sin mínimo».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // En otro motor la columna ya admite decimales.
        }

        DB::statement('ALTER TABLE productos MODIFY stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE productos MODIFY stock_maximo DECIMAL(14,3) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        // A propósito vacío: volver a entero truncaría los decimales que ya se guardaron.
    }
};
