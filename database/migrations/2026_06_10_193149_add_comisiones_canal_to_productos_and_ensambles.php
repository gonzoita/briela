<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (! Schema::hasColumn('productos', 'comision_min_distribuidor')) {
                $table->decimal('comision_min_distribuidor', 5, 2)->default(0)->after('comision_pct_maxima');
            }
            if (! Schema::hasColumn('productos', 'comision_max_distribuidor')) {
                $table->decimal('comision_max_distribuidor', 5, 2)->default(0)->after('comision_min_distribuidor');
            }
            if (! Schema::hasColumn('productos', 'comision_min_cliente_final')) {
                $table->decimal('comision_min_cliente_final', 5, 2)->default(0)->after('comision_max_distribuidor');
            }
            if (! Schema::hasColumn('productos', 'comision_max_cliente_final')) {
                $table->decimal('comision_max_cliente_final', 5, 2)->default(0)->after('comision_min_cliente_final');
            }
        });

        Schema::table('ensambles', function (Blueprint $table) {
            if (! Schema::hasColumn('ensambles', 'comision_min_distribuidor')) {
                $table->decimal('comision_min_distribuidor', 5, 2)->default(0)->after('comision_pct_maxima');
            }
            if (! Schema::hasColumn('ensambles', 'comision_max_distribuidor')) {
                $table->decimal('comision_max_distribuidor', 5, 2)->default(0)->after('comision_min_distribuidor');
            }
            if (! Schema::hasColumn('ensambles', 'comision_min_cliente_final')) {
                $table->decimal('comision_min_cliente_final', 5, 2)->default(0)->after('comision_max_distribuidor');
            }
            if (! Schema::hasColumn('ensambles', 'comision_max_cliente_final')) {
                $table->decimal('comision_max_cliente_final', 5, 2)->default(0)->after('comision_min_cliente_final');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn([
                'comision_min_distribuidor', 'comision_max_distribuidor',
                'comision_min_cliente_final', 'comision_max_cliente_final',
            ]);
        });

        Schema::table('ensambles', function (Blueprint $table) {
            $table->dropColumn([
                'comision_min_distribuidor', 'comision_max_distribuidor',
                'comision_min_cliente_final', 'comision_max_cliente_final',
            ]);
        });
    }
};
