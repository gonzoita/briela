<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            $table->boolean('visible_op')->default(true)->after('visible_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('plantilla_componentes', function (Blueprint $table) {
            $table->dropColumn('visible_op');
        });
    }
};
