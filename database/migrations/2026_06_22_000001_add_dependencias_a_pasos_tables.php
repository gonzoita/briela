<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->json('depende_de')->nullable()->after('nivel_dificultad');
            $table->boolean('es_paso_final')->default(false)->after('depende_de');
        });

        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->json('depende_de')->nullable()->after('orden');
            $table->boolean('es_paso_final')->default(false)->after('depende_de');
        });
    }

    public function down(): void
    {
        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn(['depende_de', 'es_paso_final']);
        });

        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn(['depende_de', 'es_paso_final']);
        });
    }
};
