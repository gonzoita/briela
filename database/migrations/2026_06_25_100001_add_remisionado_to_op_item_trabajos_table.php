<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (!Schema::hasColumn('op_item_trabajos', 'remisionado')) {
                $table->boolean('remisionado')->default(false)->after('token_trabajo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('op_item_trabajos', function (Blueprint $table) {
            if (Schema::hasColumn('op_item_trabajos', 'remisionado')) {
                $table->dropColumn('remisionado');
            }
        });
    }
};
