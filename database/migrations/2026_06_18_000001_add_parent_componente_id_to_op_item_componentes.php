<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('op_item_componentes', 'parent_componente_id')) {
            Schema::table('op_item_componentes', function (Blueprint $table) {
                $table->unsignedBigInteger('parent_componente_id')->nullable()->after('observacion');
                $table->foreign('parent_componente_id')->references('id')->on('op_item_componentes')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('op_item_componentes', 'parent_componente_id')) {
            Schema::table('op_item_componentes', function (Blueprint $table) {
                $table->dropForeign(['parent_componente_id']);
                $table->dropColumn('parent_componente_id');
            });
        }
    }
};
