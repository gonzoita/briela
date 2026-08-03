<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (!Schema::hasColumn('op_item_trabajos', 'numero_unidad')) {
                $table->unsignedTinyInteger('numero_unidad')->default(1)->after('porcentaje_avance');
            }
            if (!Schema::hasColumn('op_item_trabajos', 'total_unidades')) {
                $table->unsignedTinyInteger('total_unidades')->default(1)->after('numero_unidad');
            }
        });
    }

    public function down(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (Schema::hasColumn('op_item_trabajos', 'total_unidades')) {
                $table->dropColumn('total_unidades');
            }
            if (Schema::hasColumn('op_item_trabajos', 'numero_unidad')) {
                $table->dropColumn('numero_unidad');
            }
        });
    }
};
