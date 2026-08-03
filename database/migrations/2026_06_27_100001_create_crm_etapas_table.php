<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('crm_etapas')) {
            Schema::create('crm_etapas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100);
                $table->string('color', 7)->default('#6B7280');
                $table->integer('orden')->default(0);
                $table->enum('accion_automatica', ['ninguna', 'cotizacion', 'op'])->default('ninguna');
                $table->boolean('es_ganado')->default(false);
                $table->boolean('es_perdido')->default(false);
                $table->boolean('activa')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_etapas');
    }
};
