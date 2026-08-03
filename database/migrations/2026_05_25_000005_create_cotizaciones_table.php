<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cotizaciones')) return;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
