<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ops', 'porcentaje_avance')) return;

        Schema::table('ops', function (Blueprint $table) {
            $table->decimal('porcentaje_avance', 5, 2)->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropColumn('porcentaje_avance');
        });
    }
};
