<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_plantillas', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_plantillas', 'ancho_mm')) {
                $table->integer('ancho_mm')->nullable()->after('orientacion');
            }
            if (!Schema::hasColumn('pdf_plantillas', 'alto_mm')) {
                $table->integer('alto_mm')->nullable()->after('ancho_mm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_plantillas', function (Blueprint $table) {
            $table->dropColumn(['ancho_mm', 'alto_mm']);
        });
    }
};
