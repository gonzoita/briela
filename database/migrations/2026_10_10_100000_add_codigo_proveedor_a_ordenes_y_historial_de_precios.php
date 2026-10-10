<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El código que cada proveedor le da a lo que nos vende, y el rastro de sus precios.
 *
 * Una bisagra que la empresa llama IC5260 la venden tres proveedores con tres códigos
 * distintos —1256899P, R125458…—, y la orden de compra tiene que llevar el de QUIEN la
 * recibe, no el nuestro: si no, mandan otra cosa, o llaman a preguntar. La equivalencia ya
 * vivía en `producto_proveedor.referencia_proveedor`; lo que faltaba es que la orden la
 * usara, y guardarla **en la línea**: el código que se mandó en esa orden es un hecho de
 * esa orden, y no debe cambiar si mañana el proveedor cambia de catálogo.
 *
 * `producto_proveedor_precios` es la otra mitad: `producto_proveedor.precio` guarda UN
 * precio, el último, y comparar proveedores con una sola cifra por cabeza no dice si el más
 * barato siempre lo fue o si acaba de subir. Cada vez que una orden sale al proveedor queda
 * una fila: ahí está el rastro que el asistente necesita para recomendar con datos y no con
 * la última cifra que alguien escribió.
 *
 * Solo agrega. Las bases de los clientes que ya tienen órdenes quedan con la columna en nulo:
 * una orden vieja se lee igual que antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ordenes_compra_items', 'referencia_proveedor')) {
            Schema::table('ordenes_compra_items', function (Blueprint $table) {
                $table->string('referencia_proveedor', 80)->nullable()->after('item_id');
            });
        }

        if (! Schema::hasTable('producto_proveedor_precios')) {
            Schema::create('producto_proveedor_precios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
                $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
                $table->decimal('precio', 14, 2);
                // De qué orden salió. Si la orden se borra, el precio se queda: ya es historia.
                $table->foreignId('orden_compra_id')->nullable()->constrained('ordenes_compra')->nullOnDelete();
                $table->date('registrado_el');
                $table->timestamps();

                $table->index(['producto_id', 'proveedor_id', 'registrado_el'], 'pp_precios_consulta');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_proveedor_precios');

        if (Schema::hasColumn('ordenes_compra_items', 'referencia_proveedor')) {
            Schema::table('ordenes_compra_items', fn (Blueprint $table) => $table->dropColumn('referencia_proveedor'));
        }
    }
};
