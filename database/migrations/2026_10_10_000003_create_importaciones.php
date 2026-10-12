<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La liquidación de una importación: cuánto cuesta de verdad lo que llegó de afuera.
 *
 * Un embarque trae varios productos y los gastos son del embarque entero (flete, seguro,
 * agente de aduana…), así que se reparten entre los productos. De ahí sale el costo puesto en
 * bodega de cada uno, que es el que alimenta el precio de venta.
 *
 *  - `importaciones`: el embarque. Su moneda es la de la factura del proveedor; `tasa` dice
 *    cuántas unidades de la moneda base vale una de esa moneda, el día de la liquidación.
 *  - `importacion_costos`: flete, seguro y gastos. Pueden estar en la moneda de la factura o
 *    en la moneda base (el flete internacional suele venir en dólares; el agente de aduana
 *    cobra en moneda local).
 *  - `importacion_items`: los productos, con su arancel y el resultado de la liquidación.
 *
 * También el código arancelario (Sistema Armonizado) y el arancel habitual de cada producto,
 * para que la próxima liquidación arranque con ellos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('importaciones')) {
            Schema::create('importaciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sede_id')->nullable()->constrained('sedes')->nullOnDelete();
                $table->string('numero', 30)->unique();
                $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
                $table->foreignId('orden_compra_id')->nullable()->constrained('ordenes_compra')->nullOnDelete();
                $table->string('estado', 20)->default('borrador');   // borrador | liquidada
                $table->date('fecha');
                $table->string('referencia', 100)->nullable();       // factura comercial, B/L, guía…
                $table->string('pais_origen', 2)->nullable();
                $table->string('incoterm', 3)->nullable();
                $table->string('moneda', 3);
                $table->decimal('tasa', 20, 8)->default(1);
                $table->date('tasa_fecha')->nullable();
                $table->string('metodo_prorrateo', 10)->default('valor'); // valor | peso | cantidad
                $table->boolean('iva_descontable')->default(true);
                $table->text('notas')->nullable();

                // El resultado, en la moneda base. Solo se llena al liquidar.
                $table->decimal('total_fob', 18, 2)->nullable();
                $table->decimal('total_flete_seguro', 18, 2)->nullable();
                $table->decimal('total_arancel', 18, 2)->nullable();
                $table->decimal('total_iva', 18, 2)->nullable();
                $table->decimal('total_gastos', 18, 2)->nullable();
                $table->decimal('total_costo', 18, 2)->nullable();
                $table->timestamp('liquidada_at')->nullable();
                $table->foreignId('liquidada_por')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('importacion_costos')) {
            Schema::create('importacion_costos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('importacion_id')->constrained('importaciones')->cascadeOnDelete();
                $table->string('tipo', 20);                          // flete | seguro | gasto
                $table->string('concepto', 120);
                $table->decimal('valor', 18, 4);
                $table->boolean('en_moneda_base')->default(false);
                $table->unsignedSmallInteger('orden')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('importacion_items')) {
            Schema::create('importacion_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('importacion_id')->constrained('importaciones')->cascadeOnDelete();
                $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
                $table->string('descripcion', 255);
                $table->decimal('cantidad', 14, 3);
                $table->decimal('valor_unitario', 18, 4);            // FOB, en la moneda de la factura
                $table->decimal('peso_kg', 14, 3)->nullable();       // peso de TODA la línea
                $table->string('codigo_hs', 12)->nullable();
                $table->decimal('arancel_pct', 5, 2)->default(0);
                $table->decimal('iva_pct', 5, 2)->default(0);
                $table->unsignedSmallInteger('orden')->default(0);

                // La liquidación, en la moneda base.
                $table->decimal('fob', 18, 2)->nullable();
                $table->decimal('flete_seguro', 18, 2)->nullable();
                $table->decimal('cif', 18, 2)->nullable();
                $table->decimal('arancel', 18, 2)->nullable();
                $table->decimal('iva', 18, 2)->nullable();
                $table->decimal('gastos', 18, 2)->nullable();
                $table->decimal('costo_total', 18, 2)->nullable();
                $table->decimal('costo_unitario', 18, 4)->nullable();
                $table->timestamp('aplicado_at')->nullable();        // cuándo pasó a ser el costo del producto
                $table->timestamps();
            });
        }

        Schema::table('productos', function (Blueprint $table) {
            if (! Schema::hasColumn('productos', 'codigo_hs')) {
                $table->string('codigo_hs', 12)->nullable()->after('moneda_costo');
            }
            if (! Schema::hasColumn('productos', 'arancel_pct')) {
                $table->decimal('arancel_pct', 5, 2)->nullable()->after('codigo_hs');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacion_items');
        Schema::dropIfExists('importacion_costos');
        Schema::dropIfExists('importaciones');
        Schema::table('productos', fn (Blueprint $t) => $t->dropColumn(['codigo_hs', 'arancel_pct']));
    }
};
