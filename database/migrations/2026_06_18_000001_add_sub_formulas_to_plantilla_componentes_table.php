<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plantilla_componentes') && !Schema::hasColumn('plantilla_componentes', 'sub_formulas')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->json('sub_formulas')->nullable()->after('formula_real');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plantilla_componentes') && Schema::hasColumn('plantilla_componentes', 'sub_formulas')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->dropColumn('sub_formulas');
            });
        }
    }
};
