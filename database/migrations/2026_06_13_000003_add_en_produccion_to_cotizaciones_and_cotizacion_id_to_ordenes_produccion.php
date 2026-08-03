<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ordenes_produccion', 'cotizacion_id')) {
            Schema::table('ordenes_produccion', function (Blueprint $table) {
                $table->unsignedBigInteger('cotizacion_id')->nullable()->after('id');
                $table->foreign('cotizacion_id')
                    ->references('id')->on('cotizaciones')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('cotizaciones', 'en_produccion')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->boolean('en_produccion')->default(false)->after('estado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ordenes_produccion', 'cotizacion_id')) {
            Schema::table('ordenes_produccion', function (Blueprint $table) {
                $table->dropForeign(['cotizacion_id']);
                $table->dropColumn('cotizacion_id');
            });
        }

        if (Schema::hasColumn('cotizaciones', 'en_produccion')) {
            Schema::table('cotizaciones', function (Blueprint $table) {
                $table->dropColumn('en_produccion');
            });
        }
    }
};
