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
        Schema::table('op_item_componentes', function (Blueprint $table) {
            if (!Schema::hasColumn('op_item_componentes', 'observacion')) {
                $table->text('observacion')->nullable()->after('es_informativo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('op_item_componentes', function (Blueprint $table) {
            if (Schema::hasColumn('op_item_componentes', 'observacion')) {
                $table->dropColumn('observacion');
            }
        });
    }
};
