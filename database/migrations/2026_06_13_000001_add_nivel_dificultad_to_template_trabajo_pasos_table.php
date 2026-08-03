<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('template_trabajo_pasos', 'nivel_dificultad')) return;

        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->tinyInteger('nivel_dificultad')->default(1)->after('orden');
        });
    }

    public function down(): void
    {
        Schema::table('template_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn('nivel_dificultad');
        });
    }
};
