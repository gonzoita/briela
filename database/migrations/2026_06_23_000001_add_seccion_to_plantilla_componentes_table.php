<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plantilla_componentes') && !Schema::hasColumn('plantilla_componentes', 'seccion')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->string('seccion', 100)->nullable()->default(null)->after('notas');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plantilla_componentes') && Schema::hasColumn('plantilla_componentes', 'seccion')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->dropColumn('seccion');
            });
        }
    }
};
