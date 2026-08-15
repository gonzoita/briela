<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios proveedores por producto, con el precio de cada uno.
 *
 * `productos.proveedor_id` guarda **uno**, y eso alcanza para saber a quién se le compró la
 * última vez. No alcanza para lo que de verdad se hace antes de comprar: mirar los tres que
 * lo venden y elegir. Esa comparación se hacía por fuera del sistema —en un cuaderno o en un
 * chat— y por eso se compraba caro sin darse cuenta.
 *
 * Cada fila es «este proveedor me vende este producto a este precio». Lo que hace la
 * comparación útil no es solo el precio:
 *
 * - `dias_entrega`: el más barato que llega en tres semanas no sirve para una OP de mañana.
 * - `minimo_compra`: un precio bueno comprando cien no es un precio bueno comprando dos.
 * - `actualizado_el`: un precio de hace ocho meses no es un precio, es un recuerdo. Sin esta
 *   fecha, comparar tres cifras de distintas épocas da una respuesta con cara de exacta.
 * - `referencia_proveedor`: el código con el que ese proveedor lo llama, que casi nunca es el
 *   nuestro. Es lo que se escribe en la orden de compra para que no manden otra cosa.
 *
 * `productos.proveedor_id` **no se toca**: sigue existiendo y el código que lo lee sigue
 * funcionando. La regla 2 del proyecto —migraciones hacia adelante y nunca destructivas— y al
 * otro lado hay bases de clientes. Se mantiene apuntando al proveedor preferido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('producto_proveedor')) {
            return;
        }

        Schema::create('producto_proveedor', function (Blueprint $table) {
            $table->id();

            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            // Si se borra un proveedor, sus precios se van con él: un precio sin proveedor no
            // se puede comparar ni convertir en una orden de compra.
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();

            $table->string('referencia_proveedor', 80)->nullable();
            $table->decimal('precio', 14, 2)->default(0);
            $table->unsignedSmallInteger('dias_entrega')->nullable();
            $table->decimal('minimo_compra', 12, 3)->nullable();

            // El preferido: el que gana cuando no se está comparando a mano. Es también el
            // que se copia a `productos.proveedor_id`.
            $table->boolean('es_preferido')->default(false);

            $table->date('actualizado_el')->nullable();
            $table->text('notas')->nullable();

            $table->timestamps();

            // Un proveedor no puede tener dos precios para el mismo producto: serían dos
            // respuestas a la misma pregunta y nadie sabría cuál rige.
            $table->unique(['producto_id', 'proveedor_id'], 'producto_proveedor_unico');
        });

        // Lo que ya está guardado en `proveedor_id` se convierte en la primera fila, marcada
        // como preferida y con el costo actual del producto. Sin esto, abrir un producto que
        // ya tenía proveedor mostraría la lista vacía y parecería que se perdió el dato.
        $conProveedor = DB::table('productos')
            ->whereNotNull('proveedor_id')
            ->get(['id', 'proveedor_id', 'precio_costo']);

        foreach ($conProveedor as $producto) {
            $existe = DB::table('proveedores')->where('id', $producto->proveedor_id)->exists();

            if (! $existe) {
                continue;
            }

            DB::table('producto_proveedor')->insert([
                'producto_id'    => $producto->id,
                'proveedor_id'   => $producto->proveedor_id,
                'precio'         => $producto->precio_costo ?? 0,
                'es_preferido'   => true,
                // Se deja sin fecha a propósito: no se sabe de cuándo es ese precio, y
                // ponerle hoy sería afirmar algo que nadie verificó.
                'actualizado_el' => null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_proveedor');
    }
};
