<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('producto_stock')) return;

        Schema::create('producto_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('bodega_id')->constrained('bodegas')->cascadeOnDelete();
            $table->decimal('cantidad', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['producto_id', 'bodega_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_stock');
    }
};
