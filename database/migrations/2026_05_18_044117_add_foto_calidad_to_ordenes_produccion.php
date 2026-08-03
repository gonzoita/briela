<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ordenes_produccion', function (Blueprint $table) {
            $table->string('foto_calidad')->nullable()->after('observaciones_calidad');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_produccion', function (Blueprint $table) {
            $table->dropColumn('foto_calidad');
        });
    }
};
