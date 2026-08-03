<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('op_item_componentes') && !Schema::hasColumn('op_item_componentes', 'seccion')) {
            Schema::table('op_item_componentes', function (Blueprint $table) {
                $table->string('seccion', 100)->nullable()->default(null)->after('observacion');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('op_item_componentes') && Schema::hasColumn('op_item_componentes', 'seccion')) {
            Schema::table('op_item_componentes', function (Blueprint $table) {
                $table->dropColumn('seccion');
            });
        }
    }
};
