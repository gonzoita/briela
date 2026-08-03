<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ensamble_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ensamble_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('componente_id')->constrained('productos')->cascadeOnDelete();
            $table->integer('cantidad')->default(1);
            // Snapshot del precio_costo al momento de agregar
            $table->decimal('precio_costo_snapshot', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['ensamble_id', 'componente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ensamble_items');
    }
};
