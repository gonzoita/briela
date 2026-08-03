<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_compra_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('solicitud_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('descripcion');
            $table->decimal('cantidad', 12, 3);
            $table->string('unidad');
            $table->decimal('precio_estimado', 12, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('solicitud_id')->references('id')->on('solicitudes_compra')->cascadeOnDelete();
            $table->foreign('item_id')->references('id')->on('productos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_compra_items');
    }
};
