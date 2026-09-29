<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un producto puede costar en otra moneda.
 *
 * `precio_costo` sigue siendo el costo en PESOS, y todo lo que ya lo lee —ensambles,
 * precios por canal, inventario, informes— sigue funcionando igual. Lo nuevo es de dónde
 * sale: si el producto se compra en dólares o euros, `costo_moneda` guarda lo que cobra el
 * proveedor en su moneda, y el costo en pesos se recalcula con la tasa de cada día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (! Schema::hasColumn('productos', 'moneda_costo')) {
                $table->string('moneda_costo', 3)->default('COP')->after('precio_costo');
            }
            if (! Schema::hasColumn('productos', 'costo_moneda')) {
                // Cuatro decimales: un tornillo de 0,0375 USD existe.
                $table->decimal('costo_moneda', 14, 4)->nullable()->after('moneda_costo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['moneda_costo', 'costo_moneda']);
        });
    }
};
