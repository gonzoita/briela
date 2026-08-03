<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('op_item_trabajo_pasos')) return;
        if (Schema::hasColumn('op_item_trabajo_pasos', 'fotos')) return;
        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->json('fotos')->nullable()->after('tiempo_minutos');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('op_item_trabajo_pasos')) return;
        if (!Schema::hasColumn('op_item_trabajo_pasos', 'fotos')) return;
        Schema::table('op_item_trabajo_pasos', function (Blueprint $table) {
            $table->dropColumn('fotos');
        });
    }
};
