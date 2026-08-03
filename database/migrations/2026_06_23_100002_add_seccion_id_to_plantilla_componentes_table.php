<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plantilla_componentes') && !Schema::hasColumn('plantilla_componentes', 'seccion_id')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->foreignId('seccion_id')
                      ->nullable()
                      ->after('seccion')
                      ->constrained('plantilla_secciones')
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plantilla_componentes') && Schema::hasColumn('plantilla_componentes', 'seccion_id')) {
            Schema::table('plantilla_componentes', function (Blueprint $table) {
                $table->dropConstrainedForeignId('seccion_id');
            });
        }
    }
};
