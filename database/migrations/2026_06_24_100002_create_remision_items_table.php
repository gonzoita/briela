<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('remision_items')) return;

        Schema::create('remision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remision_id')->constrained('remisiones')->cascadeOnDelete();
            $table->foreignId('op_item_id')->nullable()->constrained('op_items')->nullOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->string('descripcion');
            $table->decimal('cantidad', 10, 3);
            $table->string('unidad')->nullable();
            $table->string('numero_serie')->nullable();
            $table->string('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remision_items');
    }
};
