<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('comision_pct_minima', 5, 2)->default(0)->after('precio_cliente_final');
            $table->decimal('comision_pct_maxima', 5, 2)->default(0)->after('comision_pct_minima');
            $table->decimal('utilidad_minima_empresa_pct', 5, 2)->default(15)->after('comision_pct_maxima');
            $table->decimal('descuento_max_cliente_final', 5, 2)->default(3)->after('utilidad_minima_empresa_pct');
            $table->decimal('descuento_max_distribuidor', 5, 2)->default(5)->after('descuento_max_cliente_final');
            $table->decimal('descuento_max_mayorista', 5, 2)->default(8)->after('descuento_max_distribuidor');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn([
                'comision_pct_minima', 'comision_pct_maxima',
                'utilidad_minima_empresa_pct',
                'descuento_max_cliente_final', 'descuento_max_distribuidor', 'descuento_max_mayorista',
            ]);
        });
    }
};
