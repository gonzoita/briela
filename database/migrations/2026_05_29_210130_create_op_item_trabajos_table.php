<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_trabajos')) return;

        Schema::create('op_item_trabajos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('op_item_id');
            $table->foreign('op_item_id')->references('id')->on('op_items')->cascadeOnDelete();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->foreign('template_id')->references('id')->on('templates_trabajo')->nullOnDelete();
            $table->decimal('porcentaje_avance', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_item_trabajos');
    }
};
