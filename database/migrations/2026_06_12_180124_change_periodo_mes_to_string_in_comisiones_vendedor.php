<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->string('periodo_mes_str', 7)->nullable()->after('periodo_mes');
        });

        // Copiar los primeros 7 caracteres de la fecha ('2026-06-01' → '2026-06')
        DB::statement("UPDATE comisiones_vendedor SET periodo_mes_str = LEFT(CAST(periodo_mes AS CHAR), 7) WHERE periodo_mes IS NOT NULL");

        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->dropColumn('periodo_mes');
        });

        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->renameColumn('periodo_mes_str', 'periodo_mes');
        });
    }

    public function down(): void
    {
        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->date('periodo_mes_date')->nullable()->after('periodo_mes');
        });

        DB::statement("UPDATE comisiones_vendedor SET periodo_mes_date = CONCAT(periodo_mes, '-01') WHERE periodo_mes IS NOT NULL");

        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->dropColumn('periodo_mes');
        });

        Schema::table('comisiones_vendedor', function (Blueprint $table) {
            $table->renameColumn('periodo_mes_date', 'periodo_mes');
        });
    }
};
