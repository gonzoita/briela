<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            if (!Schema::hasColumn('productos', 'configuracion_puerta')) {
                $table->json('configuracion_puerta')->nullable()->after('descripcion_larga');
            }
            if (!Schema::hasColumn('productos', 'margen_aplicado')) {
                $table->decimal('margen_aplicado', 5, 2)->default(30.00)->after('configuracion_puerta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn(['configuracion_puerta', 'margen_aplicado']);
        });
    }
};
