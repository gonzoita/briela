<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fusión Plantillas de Ensamble <-> Trabajo: el editor de pasos dentro de la
// plantilla de ensamble ahora también necesita objetivo del paso y adjuntos
// (imagen / plano de referencia). Se agregan solo al template (no a
// op_item_trabajo_pasos) porque por ahora son material de referencia para
// definir el paso, no algo que cambie por unidad fabricada.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('template_trabajo_pasos', 'objetivo')) return;

        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->text('objetivo')->nullable()->after('nombre');
            $table->string('imagen')->nullable()->after('depende_de');
            $table->string('archivo_plano')->nullable()->after('imagen');
        });
    }

    public function down(): void
    {
        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn(['objetivo', 'imagen', 'archivo_plano']);
        });
    }
};
