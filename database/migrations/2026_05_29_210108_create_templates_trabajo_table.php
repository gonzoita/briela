<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('templates_trabajo')) return;

        Schema::create('templates_trabajo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plantilla_ensamble_id')->nullable();
            $table->foreign('plantilla_ensamble_id')->references('id')->on('plantillas_ensamble')->nullOnDelete();
            $table->string('nombre', 200);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates_trabajo');
    }
};
