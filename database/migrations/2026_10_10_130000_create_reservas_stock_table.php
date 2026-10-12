<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El stock que una cotización enviada tiene apartado, por un máximo de 24 horas.
 *
 * Una cotización prometía unidades que nadie estaba guardando: dos vendedores podían cotizar las
 * mismas cinco bisagras y descubrirlo el día que el cliente decía que sí. La reserva es una
 * **marca** y no un movimiento de inventario: no baja el stock real, que sigue donde está hasta
 * que se despacha, y por eso no hay nada que deshacer cuando vence.
 *
 * Una fila por cotización y producto, con las cantidades de todas sus líneas sumadas:
 * `unique(cotizacion_id, producto_id)`. Las líneas de una cotización se recrean al editarla, así
 * que la reserva no cuelga de la línea sino de lo que se promete.
 *
 * Estados:
 *  - `activa`: aparta stock, hasta `expira_at`.
 *  - `concretada`: la cotización se aprobó. Deja de apartar; de ahí en adelante manda la OP.
 *  - `liberada`: la cotización se rechazó, venció, volvió a borrador, se borró, o se quitó la línea.
 *  - `vencida`: pasaron las 24 horas sin que se concretara.
 *  - `cedida`: una venta usó las unidades. `cantidad_cedida` dice cuántas, y `cedida_a_cotizacion_id`
 *    a favor de cuál venta.
 *
 * Solo agrega: las instalaciones existentes empiezan sin ninguna fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reservas_stock')) {
            return;
        }

        Schema::create('reservas_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->decimal('cantidad', 12, 3);
            // Cuánto de lo apartado ya se usó para una venta. Lo que aparta de verdad es la
            // diferencia: una venta puede llevarse solo una parte.
            $table->decimal('cantidad_cedida', 12, 3)->default(0);
            $table->string('estado', 15)->default('activa');
            $table->timestamp('expira_at');
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cedida_a_cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cotizacion_id', 'producto_id'], 'reservas_cotizacion_producto');
            // Las dos preguntas que se hacen todo el tiempo: «¿cuánto hay apartado de este
            // producto?» y «¿qué venció ya?».
            $table->index(['producto_id', 'estado'], 'reservas_producto_estado');
            $table->index(['estado', 'expira_at'], 'reservas_estado_expira');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_stock');
    }
};
