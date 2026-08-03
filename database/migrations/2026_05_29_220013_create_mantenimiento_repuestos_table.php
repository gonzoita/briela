<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mantenimiento_repuestos')) {
            Schema::create('mantenimiento_repuestos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mantenimiento_id')->constrained('mantenimientos')->cascadeOnDelete();
                $table->string('nombre');
                $table->string('referencia')->nullable();
                $table->string('unidad')->default('und');
                $table->decimal('cantidad', 10, 2)->default(1);
                $table->decimal('precio_unitario', 12, 2)->default(0);
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mantenimiento_repuestos');
    }
};
