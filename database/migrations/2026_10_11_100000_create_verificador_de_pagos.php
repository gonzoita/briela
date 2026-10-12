<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El verificador de pagos: las cuentas del banco de la empresa y lo que entra a ellas.
 *
 * Solo hacia adelante: crea tablas nuevas y agrega una columna nula a `op_pagos`.
 *
 * `movimientos_bancarios` guarda **solo lo que entra** (abonos): el verificador responde «¿llegó
 * el dinero?», no lleva la contabilidad de la cuenta. Lo que sale se descarta al importar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cuentas_bancarias')) {
            Schema::create('cuentas_bancarias', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 120);                 // «Bancolombia ahorros principal»
                $table->string('banco', 80)->nullable();
                $table->string('ultimos_digitos', 10)->nullable(); // nunca el número completo
                $table->string('moneda', 3)->default('COP');
                $table->boolean('activa')->default(true);
                // De dónde llegan sus movimientos: `extracto` (archivo subido) hoy; una conexión
                // de banca abierta después. `referencia_externa` es el identificador de la
                // conexión en el proveedor, si lo hay.
                $table->string('origen', 20)->default('extracto');
                $table->string('referencia_externa', 120)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('movimientos_bancarios')) {
            Schema::create('movimientos_bancarios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias')->cascadeOnDelete();
                // El identificador del banco, cuando lo da; y una huella calculada con el resto,
                // que es lo que evita contar dos veces un movimiento que llega por dos caminos
                // o en dos extractos que se traslapan.
                $table->string('id_externo', 120)->nullable();
                $table->string('huella', 64);
                $table->date('fecha');
                $table->decimal('valor', 14, 2);
                $table->text('descripcion')->nullable();
                $table->string('referencia', 200)->nullable();
                $table->string('contraparte', 200)->nullable();
                // pendiente → sin dueño todavía · dudoso → hay candidatas y alguien decide ·
                // conciliado → ya es un pago de una cuota · ignorado → no es de la empresa
                $table->string('estado', 15)->default('pendiente');
                $table->foreignId('op_pago_id')->nullable()->constrained('op_pagos')->nullOnDelete();
                $table->foreignId('conciliado_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('conciliado_at')->nullable();
                $table->boolean('automatico')->default(false);
                $table->string('nota', 300)->nullable();
                $table->string('origen', 20)->default('extracto');
                $table->timestamps();

                $table->unique(['cuenta_bancaria_id', 'huella']);
                $table->index(['estado', 'fecha']);
                $table->index('valor');
            });
        }

        if (Schema::hasTable('op_pagos') && ! Schema::hasColumn('op_pagos', 'verificado_at')) {
            Schema::table('op_pagos', function (Blueprint $table) {
                // Cuándo el banco confirmó este pago. Nulo = alguien lo anotó, sin confirmación.
                $table->timestamp('verificado_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante: no se borran tablas ni columnas con datos de un cliente.
    }
};
