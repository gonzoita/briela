<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->decimal('comision_pct_aplicada', 5, 2)->default(0)->after('total_linea');
            $table->decimal('comision_valor', 12, 2)->default(0)->after('comision_pct_aplicada');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_items', function (Blueprint $table) {
            $table->dropColumn(['comision_pct_aplicada', 'comision_valor']);
        });
    }
};
