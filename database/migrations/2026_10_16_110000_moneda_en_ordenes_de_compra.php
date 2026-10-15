<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La orden de compra puede ser en otra moneda (un proveedor del exterior factura en dólares).
 *
 * Sigue la regla de las cotizaciones: **por dentro todo está en la moneda base**. La orden guarda
 * sus líneas y totales en pesos y, aparte, la moneda y la tasa con las que se escribió; el valor
 * que la persona tecleó en su moneda queda en `precio_moneda` para mostrarlo exacto (reconvertir
 * pesos a dólares dejaba centavos de diferencia). Así la recepción, el costo promedio, la cartera
 * de compras y los informes siguen sumando una sola moneda.
 *
 * Aditiva y nulable: una orden anterior no tiene moneda y se entiende en la moneda base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes_compra', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes_compra', 'moneda')) {
                $table->string('moneda', 3)->nullable()->after('proveedor_id');
            }
            if (! Schema::hasColumn('ordenes_compra', 'tasa_cambio')) {
                $table->decimal('tasa_cambio', 18, 8)->nullable()->after('moneda');
            }
            if (! Schema::hasColumn('ordenes_compra', 'tasa_fecha')) {
                $table->date('tasa_fecha')->nullable()->after('tasa_cambio');
            }
        });

        Schema::table('ordenes_compra_items', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes_compra_items', 'precio_moneda')) {
                $table->decimal('precio_moneda', 18, 4)->nullable()->after('precio_unitario');
            }
        });
    }

    public function down(): void
    {
        // A propósito vacío: son datos de un cliente al que no tenemos acceso.
    }
};
