<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('ensambles', 'descripcion_corta')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->string('descripcion_corta', 1000)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ensambles', 'descripcion_corta')) {
            Schema::table('ensambles', function (Blueprint $table) {
                $table->string('descripcion_corta', 160)->nullable()->change();
            });
        }
    }
};
