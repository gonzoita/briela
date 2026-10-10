<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué papel respalda cada movimiento de inventario, y qué llegó en cada recepción.
 *
 * Un movimiento decía «entrada, 10 unidades» y, a lo sumo, una nota. Pero la pregunta que
 * hace quien audita un inventario es otra: ¿con qué factura o remisión entró esto? Sin eso,
 * una entrada es un número que nadie puede comprobar.
 *
 * - `producto_movimientos.documento_*`: el papel que lo respalda —factura, remisión u otro—,
 *   su número y su fecha. Lo llena la recepción de una orden de compra con lo que trae el
 *   proveedor, y se puede escribir en un ajuste manual.
 *
 * - `ordenes_compra_recepciones`: una fila por CADA recepción, no una por orden. Una orden
 *   llega a veces en tres entregas, cada una con su remisión y su factura; guardar «la»
 *   factura de la orden obligaba a escoger una. También es lo que permite saber si una
 *   entrega llegó dentro del plazo pactado, que es lo que mide la calificación del proveedor.
 *
 * Solo agrega, y todo nace nulo: lo que ya estaba guardado se lee igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producto_movimientos', function (Blueprint $table) {
            if (! Schema::hasColumn('producto_movimientos', 'documento_tipo')) {
                $table->string('documento_tipo', 20)->nullable()->after('origen_id');
            }
            if (! Schema::hasColumn('producto_movimientos', 'documento_numero')) {
                $table->string('documento_numero', 60)->nullable()->after('documento_tipo');
            }
            if (! Schema::hasColumn('producto_movimientos', 'documento_fecha')) {
                $table->date('documento_fecha')->nullable()->after('documento_numero');
            }
        });

        if (! Schema::hasTable('ordenes_compra_recepciones')) {
            Schema::create('ordenes_compra_recepciones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
                $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();
                // Cuándo llegó la mercancía: no cuándo se digitó, que puede ser días después.
                $table->date('fecha_recepcion');
                $table->string('factura_numero', 60)->nullable();
                $table->string('remision_numero', 60)->nullable();
                $table->date('fecha_documento')->nullable();
                $table->text('observaciones')->nullable();
                $table->timestamps();

                $table->index('orden_compra_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra_recepciones');

        Schema::table('producto_movimientos', function (Blueprint $table) {
            foreach (['documento_fecha', 'documento_numero', 'documento_tipo'] as $columna) {
                if (Schema::hasColumn('producto_movimientos', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
