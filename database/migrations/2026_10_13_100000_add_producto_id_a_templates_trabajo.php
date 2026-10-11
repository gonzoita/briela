<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un producto también puede pasar por planta: cortarlo, pintarlo, empacarlo. Su flujo de pasos
 * cuelga de él, igual que el de un ensamble directo cuelga del ensamble. Solo agrega una
 * columna nula: los flujos que ya existen siguen siendo de su plantilla o de su ensamble.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('templates_trabajo') && ! Schema::hasColumn('templates_trabajo', 'producto_id')) {
            Schema::table('templates_trabajo', function (Blueprint $table) {
                $table->unsignedBigInteger('producto_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Hacia adelante: no se borran columnas de una instalación.
    }
};
