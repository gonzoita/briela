<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué bodega salió el material de ESTA unidad.
 *
 * `bodega_entrega_id` ya guardaba a dónde entró lo fabricado. Faltaba la pareja: de dónde
 * salieron los insumos. La orden declara las dos al confirmarse y eso sigue siendo el plan,
 * pero el paso final ahora las muestra y deja corregirlas —quien deja la unidad en el estante
 * es el único que sabe en cuál quedó, y quien la armó, de qué caja sacó el material—.
 *
 * Se guarda **por unidad** y no en la orden porque un lote se puede partir: tres puertas con
 * material de la bodega principal y dos con el de la sucursal es un caso real, y escribirlo en
 * la orden borraría el dato de las tres primeras.
 *
 * Nullable y hacia adelante: las unidades ya entregadas se quedan sin él, y ahí sigue valiendo
 * lo que diga la orden. No se reescribe historia de inventario.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('op_item_trabajos', 'bodega_material_id')) {
            return;
        }

        Schema::table('op_item_trabajos', function (Blueprint $table) {
            $table->foreignId('bodega_material_id')
                ->nullable()
                ->after('bodega_entrega_id')
                ->constrained('bodegas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('op_item_trabajos', 'bodega_material_id')) {
            return;
        }

        Schema::table('op_item_trabajos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bodega_material_id');
        });
    }
};
