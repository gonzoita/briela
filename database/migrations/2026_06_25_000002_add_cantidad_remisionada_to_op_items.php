<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('op_items', 'cantidad_remisionada')) {
            Schema::table('op_items', function (Blueprint $table) {
                $table->decimal('cantidad_remisionada', 10, 3)->default(0)->after('remision_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('op_items', 'cantidad_remisionada')) {
            Schema::table('op_items', function (Blueprint $table) {
                $table->dropColumn('cantidad_remisionada');
            });
        }
    }
};
