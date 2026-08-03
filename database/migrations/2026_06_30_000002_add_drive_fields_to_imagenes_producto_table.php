<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imagenes_producto', function (Blueprint $table) {
            $table->string('drive_id')->nullable()->after('ruta');
        });
    }

    public function down(): void
    {
        Schema::table('imagenes_producto', function (Blueprint $table) {
            $table->dropColumn('drive_id');
        });
    }
};
