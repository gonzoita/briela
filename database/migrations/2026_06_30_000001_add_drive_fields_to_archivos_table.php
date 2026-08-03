<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->string('drive_id')->nullable()->after('ruta');
            $table->string('drive_url')->nullable()->after('drive_id');
            $table->enum('storage', ['local', 'drive'])->default('local')->after('drive_url');
        });
    }

    public function down(): void
    {
        Schema::table('archivos', function (Blueprint $table) {
            $table->dropColumn(['drive_id', 'drive_url', 'storage']);
        });
    }
};
