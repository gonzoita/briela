<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('op_items', function (Blueprint $table) {
            if (!Schema::hasColumn('op_items', 'remisionado')) {
                $table->boolean('remisionado')->default(false)->after('notas_item');
            }
            if (!Schema::hasColumn('op_items', 'remision_id')) {
                $table->foreignId('remision_id')->nullable()->after('remisionado')
                    ->constrained('remisiones')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('op_items', function (Blueprint $table) {
            if (Schema::hasColumn('op_items', 'remision_id')) {
                $table->dropForeign(['remision_id']);
                $table->dropColumn('remision_id');
            }
            if (Schema::hasColumn('op_items', 'remisionado')) {
                $table->dropColumn('remisionado');
            }
        });
    }
};
