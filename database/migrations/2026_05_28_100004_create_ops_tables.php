<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ops')) {
            Schema::create('ops', function (Blueprint $table) {
                $table->id();
                $table->string('numero', 20)->unique();
                $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
                $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones')->nullOnDelete();
                $table->foreignId('responsable_id')->constrained('users');
                $table->enum('estado', ['borrador', 'confirmada', 'en_produccion', 'calidad', 'despachada'])->default('borrador');
                $table->date('fecha_creacion');
                $table->date('fecha_entrega_estimada')->nullable();
                $table->decimal('anticipo', 14, 2)->nullable();
                $table->text('condiciones')->nullable();
                $table->text('notas_internas')->nullable();
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('descuento_total', 14, 2)->default(0);
                $table->decimal('impuesto_total', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('op_items')) {
            Schema::create('op_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('op_id')->constrained('ops')->cascadeOnDelete();
                $table->enum('tipo', ['producto', 'ensamble', 'servicio']);
                $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
                $table->foreignId('ensamble_id')->nullable()->constrained('ensambles')->nullOnDelete();
                $table->integer('orden')->default(0);
                $table->string('descripcion', 500);
                $table->text('descripcion_larga')->nullable();
                $table->decimal('cantidad', 10, 2);
                $table->decimal('precio_unitario', 15, 2);
                $table->decimal('descuento_pct', 5, 2)->default(0);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('impuesto_pct', 5, 2)->default(0);
                $table->decimal('impuesto_valor', 15, 2)->default(0);
                $table->decimal('total_linea', 15, 2)->default(0);
                $table->json('variables_instancia')->nullable();
                $table->json('componentes_snapshot')->nullable();
                // Campos de producción
                $table->string('numero_serie', 100)->nullable();
                $table->foreignId('operario_id')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('estado_item', ['pendiente', 'en_proceso', 'terminado'])->default('pendiente');
                $table->text('notas_item')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('op_item_componentes')) {
            Schema::create('op_item_componentes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('op_item_id')->constrained('op_items')->cascadeOnDelete();
                $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
                $table->string('nombre', 300);
                $table->decimal('cantidad', 10, 3);
                $table->string('unidad', 50)->nullable();
                $table->boolean('es_informativo')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('op_item_componentes');
        Schema::dropIfExists('op_items');
        Schema::dropIfExists('ops');
    }
};
