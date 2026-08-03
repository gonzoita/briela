<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            if (!Schema::hasColumn('plantilla_componentes', 'formula_real')) {
                $table->text('formula_real')->nullable()->after('formula');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            if (Schema::hasColumn('plantilla_componentes', 'formula_real')) {
                $table->dropColumn('formula_real');
            }
        });
    }
};
