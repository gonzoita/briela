<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Los schemas actuales en DB son de una versión anterior — se recrean completos
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');

        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('contacto_id')->nullable()->constrained('contactos_cliente')->nullOnDelete();
            $table->string('nombre_contacto_override', 150)->nullable();
            $table->enum('moneda', ['COP', 'USD', 'EUR'])->default('COP');
            $table->decimal('tasa_cambio', 12, 4)->default(1);
            $table->date('fecha_creacion');
            $table->date('fecha_validez');
            $table->foreignId('responsable_id')->constrained('users');
            $table->enum('estado', ['borrador', 'enviada', 'aprobada', 'rechazada', 'vencida'])->default('borrador');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('descuento_total', 14, 2)->default(0);
            $table->decimal('impuesto_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('condiciones_comerciales')->nullable();
            $table->text('notas_internas')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cotizacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->enum('tipo', ['producto', 'configuracion_puerta', 'ensamble', 'texto_libre']);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('configuracion_puerta_id')->nullable()->constrained('configuraciones_puerta')->nullOnDelete();
            $table->foreignId('ensamble_id')->nullable()->constrained('ensambles')->nullOnDelete();
            $table->json('variables_snapshot')->nullable();
            $table->json('componentes_snapshot')->nullable();
            $table->integer('orden')->default(0);
            $table->text('descripcion');
            $table->decimal('cantidad', 10, 3);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('descuento_pct', 5, 2)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('impuesto_pct', 5, 2)->default(0);
            $table->decimal('impuesto_valor', 12, 2)->default(0);
            $table->decimal('total_linea', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_items');
        Schema::dropIfExists('cotizaciones');
    }
};
