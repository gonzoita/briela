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
        Schema::table('op_items', function (Blueprint $table) {
            if (!Schema::hasColumn('op_items', 'imagenes_instancia')) {
                $table->json('imagenes_instancia')->nullable()->after('variables_instancia');
            }
        });
    }

    public function down(): void
    {
        Schema::table('op_items', function (Blueprint $table) {
            $table->dropColumn('imagenes_instancia');
        });
    }
};
