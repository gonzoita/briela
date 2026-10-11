<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alistamiento parcial, lo pendiente en cada remisión, el cierre por pago y quién firmó.
 *
 * - `op_items.cantidad_alistada`: de quince bisagras vendidas se pueden alistar diez y
 *   remisionar esas diez. Antes un producto estaba alistado entero o no lo estaba.
 * - `remision_items.cantidad_pendiente`: lo que quedó por entregar de ese ítem cuando salió la
 *   remisión. Es una foto: la remisión impresa tiene que decir lo mismo mañana.
 * - `remisiones.estado = cerrada`: la remisión entregada se cierra sola cuando la orden queda
 *   pagada con pagos verificados. «Entregada» es lo físico; «Cerrada», lo cobrado.
 * - Quién firmó el despacho y cuándo, y cuándo se firmó el recibido.
 * - `op_pagos.verificado_por`: un pago en efectivo o cheque también se verifica, a mano.
 *
 * Solo agrega: ninguna columna se renombra ni se borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('op_items', 'cantidad_alistada')) {
            Schema::table('op_items', function (Blueprint $table) {
                $table->decimal('cantidad_alistada', 10, 3)->default(0)->after('cantidad_remisionada');
            });

            // Lo que ya estaba alistado, lo estaba entero; y lo remisionado salió alistado.
            DB::table('op_items')->where('estado_item', 'terminado')->update(['cantidad_alistada' => DB::raw('cantidad')]);
            DB::table('op_items')->where('estado_item', '!=', 'terminado')
                ->where('cantidad_remisionada', '>', 0)
                ->update(['cantidad_alistada' => DB::raw('cantidad_remisionada')]);
        }

        if (! Schema::hasColumn('remision_items', 'cantidad_pendiente')) {
            Schema::table('remision_items', function (Blueprint $table) {
                $table->decimal('cantidad_pendiente', 10, 3)->nullable()->after('cantidad');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE remisiones MODIFY COLUMN estado ENUM('borrador','confirmada','en_camino','entregada','cerrada','anulada') NOT NULL DEFAULT 'borrador'");
        }

        Schema::table('remisiones', function (Blueprint $table) {
            if (! Schema::hasColumn('remisiones', 'cerrada_at')) {
                $table->timestamp('cerrada_at')->nullable();
            }
            if (! Schema::hasColumn('remisiones', 'firma_despacho_por')) {
                $table->foreignId('firma_despacho_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('firma_despacho_at')->nullable();
                $table->timestamp('firma_recibido_at')->nullable();
            }
        });

        if (Schema::hasTable('op_pagos') && ! Schema::hasColumn('op_pagos', 'verificado_por')) {
            Schema::table('op_pagos', function (Blueprint $table) {
                $table->foreignId('verificado_por')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente: en una instalación con clientes no se deshace.
    }
};
